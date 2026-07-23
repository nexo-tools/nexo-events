<?php if (isset($component)) { $__componentOriginalcb8170ac00b272413fe5b25f86fc5e3a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcb8170ac00b272413fe5b25f86fc5e3a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.guest-layout','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('guest-layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <h1 class="mb-1 text-2xl font-bold"><?php echo e($event->title); ?></h1>
    <p class="mb-4 text-sm text-slate-500">
        <?php echo e($event->starts_at->format('d/m/Y H:i')); ?><?php if($event->venue): ?> · <?php echo e($event->venue); ?><?php endif; ?>
    </p>

    <?php if($event->description): ?>
        <p class="mb-6 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?php echo e($event->description); ?></p>
    <?php endif; ?>

    <?php if($event->status->value === 'published'): ?>
        <?php if(session('status')): ?>
            <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status"><?php echo e(session('status')); ?></p>
        <?php endif; ?>

        <?php if($event->isSoldOut()): ?>
            <p class="rounded-lg bg-slate-100 px-4 py-3 text-sm dark:bg-slate-800"><?php echo e(__('El evento está agotado.')); ?></p>
        <?php else: ?>
            <form method="POST" action="<?php echo e(route('public.register', $event)); ?>" class="space-y-3">
                <?php echo csrf_field(); ?>
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <div>
                    <label class="block text-sm font-medium"><?php echo e(__('Tu nombre')); ?></label>
                    <input name="name" value="<?php echo e(old('name')); ?>" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div>
                    <label class="block text-sm font-medium"><?php echo e(__('Email')); ?></label>
                    <input type="email" name="email" value="<?php echo e(old('email')); ?>" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <?php if (isset($component)) { $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.button','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?><?php echo e(__('Registrarme')); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $attributes = $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $component = $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
            </form>
        <?php endif; ?>
    <?php elseif($event->status->value === 'cancelled'): ?>
        <p class="rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900"><?php echo e(__('Este evento fue cancelado.')); ?></p>
    <?php else: ?>
        <p class="rounded-lg bg-slate-100 px-4 py-3 text-sm dark:bg-slate-800"><?php echo e(__('El registro para este evento está cerrado.')); ?></p>
    <?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcb8170ac00b272413fe5b25f86fc5e3a)): ?>
<?php $attributes = $__attributesOriginalcb8170ac00b272413fe5b25f86fc5e3a; ?>
<?php unset($__attributesOriginalcb8170ac00b272413fe5b25f86fc5e3a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcb8170ac00b272413fe5b25f86fc5e3a)): ?>
<?php $component = $__componentOriginalcb8170ac00b272413fe5b25f86fc5e3a; ?>
<?php unset($__componentOriginalcb8170ac00b272413fe5b25f86fc5e3a); ?>
<?php endif; ?>
<?php /**PATH /app/resources/views/public/event/show.blade.php ENDPATH**/ ?>