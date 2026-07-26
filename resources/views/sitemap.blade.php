<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@isset($url['lastmod'])
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endisset
        <priority>{{ $url['priority'] }}</priority>
@foreach ($locales as $locale)
        <xhtml:link rel="alternate" hreflang="{{ $locale }}" href="{{ $url['loc'] }}?lang={{ $locale }}"/>
@endforeach
    </url>
@endforeach
</urlset>
