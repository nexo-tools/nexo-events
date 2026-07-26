/**
 * Door scanner: decode a ticket QR from the rear camera and check the attendee
 * in through the endpoint the manual form already posts to.
 *
 * Progressive enhancement on purpose (SPEC-scanner): the page ships a working
 * server-rendered manual form, and everything here is layered on top. If this
 * file fails to load or the camera is refused, the door still works. An event
 * is a fixed moment in time — "the scanner is blank" at 8pm cannot be fixed by
 * a deploy, which the freeze rule forbids anyway.
 *
 * Decoding is on-device: native BarcodeDetector where it exists (Android
 * Chrome), jsQR otherwise (iOS Safari has no BarcodeDetector). jsQR is loaded
 * with a dynamic import so its ~40KB only reaches the one page that scans, and
 * it is pure JS — no wasm, so the strict CSP needs no wasm-unsafe-eval.
 */

const COOLDOWN_MS = 2500 // how long the same code is ignored after a result

export function initScanner() {
    const root = document.querySelector('[data-scanner]')
    if (!root) return

    const video = root.querySelector('[data-scanner-video]')
    const resultBox = root.querySelector('[data-scanner-result]')
    const startBtn = root.querySelector('[data-scanner-start]')
    const stopBtn = root.querySelector('[data-scanner-stop]')
    const controls = root.querySelector('[data-scanner-controls]')
    const form = document.querySelector('[data-checkin-form]')

    const labels = JSON.parse(root.dataset.scannerLabels || '{}')
    const endpoint = root.dataset.scannerEndpoint
    const token = form?.querySelector('input[name="_token"]')?.value

    if (!navigator.mediaDevices?.getUserMedia || !endpoint || !token) {
        // No camera API (or nothing to post to): leave the manual form alone.
        return
    }

    // Only now reveal the camera controls — a no-JS visitor never sees a button
    // that cannot work.
    controls?.classList.remove('hidden')

    let stream = null
    let detector = null
    let jsQR = null
    let scanning = false
    let lastCode = null
    let lastCodeAt = 0
    let busy = false

    const show = (state, message) => {
        if (!resultBox) return
        resultBox.textContent = message
        resultBox.className = [
            'mt-3 rounded-lg px-4 py-3 text-sm font-medium',
            state === 'ok' ? 'bg-green-100 text-green-900' : '',
            state === 'error' ? 'bg-red-100 text-red-900' : '',
            state === 'info' ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' : '',
        ].join(' ')
        resultBox.hidden = false
    }

    const submit = async (code) => {
        busy = true
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ token: code }),
            })

            if (response.status === 429) {
                show('error', labels.throttled)
                return
            }
            if (!response.ok) {
                show('error', labels.failed)
                return
            }

            const data = await response.json()
            const label = labels[data.result] ?? labels.unknown
            show(data.result === 'ok' ? 'ok' : 'error', data.name ? `${label} — ${data.name}` : label)
        } catch {
            // Offline at the door is a real scenario (ADR-002: v1 is online-only).
            // Say so plainly instead of looking like a rejected ticket.
            show('error', labels.offline)
        } finally {
            busy = false
        }
    }

    const handleCode = (code) => {
        const now = Date.now()
        // Damping: a QR held in frame decodes many times per second, and the
        // second read would come back "already checked in" and look like a
        // rejection to whoever is at the door.
        if (busy || (code === lastCode && now - lastCodeAt < COOLDOWN_MS)) return
        lastCode = code
        lastCodeAt = now
        submit(code)
    }

    const canvas = document.createElement('canvas')
    const ctx = canvas.getContext('2d', { willReadFrequently: true })

    const tick = async () => {
        if (!scanning) return

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            try {
                if (detector) {
                    const codes = await detector.detect(video)
                    if (codes.length > 0) handleCode(codes[0].rawValue)
                } else if (jsQR) {
                    canvas.width = video.videoWidth
                    canvas.height = video.videoHeight
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
                    const image = ctx.getImageData(0, 0, canvas.width, canvas.height)
                    const found = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })
                    if (found?.data) handleCode(found.data)
                }
            } catch {
                // A single bad frame must never end the scan session.
            }
        }

        requestAnimationFrame(tick)
    }

    const start = async () => {
        show('info', labels.starting)
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            })
        } catch {
            // Denied, or an in-app browser (Instagram/Gmail on iOS) that never
            // gets camera access. Tell them what to do instead of failing mute.
            show('error', labels.noCamera)
            return
        }

        video.srcObject = stream
        video.setAttribute('playsinline', '') // iOS: inline, not fullscreen
        await video.play()

        if ('BarcodeDetector' in window) {
            try {
                const formats = await window.BarcodeDetector.getSupportedFormats()
                if (formats.includes('qr_code')) {
                    detector = new window.BarcodeDetector({ formats: ['qr_code'] })
                }
            } catch {
                detector = null
            }
        }
        if (!detector) {
            jsQR = (await import('jsqr')).default
        }

        video.hidden = false
        startBtn.hidden = true
        stopBtn.hidden = false
        scanning = true
        show('info', labels.ready)
        requestAnimationFrame(tick)
    }

    const stop = () => {
        scanning = false
        stream?.getTracks().forEach((track) => track.stop())
        stream = null
        video.hidden = true
        video.srcObject = null
        startBtn.hidden = false
        stopBtn.hidden = true
        show('info', labels.stopped)
    }

    startBtn?.addEventListener('click', start)
    stopBtn?.addEventListener('click', stop)
    // Free the camera when leaving the page; phones keep the LED on otherwise.
    window.addEventListener('pagehide', stop)
}
