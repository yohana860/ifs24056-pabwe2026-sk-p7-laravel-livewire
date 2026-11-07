<div class="max-w-md mx-auto my-8">
    <div class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <div class="text-center mb-6">
            <h2 class="text-2xl font-bold text-slate-900">Masuk ke Akun Anda</h2>
            <p class="text-sm text-slate-500 mt-1">Kelola stok harian Anda secara teratur dan produktif</p>
        </div>

        <form wire:submit.prevent="authenticate" class="space-y-5">
            <div>
                <label for="login-username" class="block text-sm font-medium text-slate-700 mb-1">Username atau Email</label>
                <input wire:model="login" type="text" id="login-username" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition" placeholder="username atau nama@email.com" required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['login'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs text-rose-600 mt-1 block"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div>
                <label for="login-password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input wire:model="password" type="password" id="login-password" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition" placeholder="••••••••" required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs text-rose-600 mt-1 block"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center space-x-2 text-sm text-slate-600 cursor-pointer">
                    <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span>Ingat Saya</span>
                </label>
            </div>

            <button type="submit" id="login-submit" wire:loading.attr="disabled" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 disabled:opacity-75 disabled:cursor-not-allowed text-white font-semibold rounded-lg shadow-sm transition flex items-center justify-center space-x-2">
                <svg wire:loading wire:target="authenticate" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span wire:loading.remove wire:target="authenticate">Masuk</span>
                <span wire:loading wire:target="authenticate">Memverifikasi...</span>
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-slate-500">
            Belum memiliki akun?
            <a href="<?php echo e(route('register')); ?>" class="font-semibold text-blue-600 hover:text-blue-700">Daftar sekarang</a>
        </div>
    </div>
</div>
<?php /**PATH C:\Users\ASUS\Downloads\pabwe-p7-stock\resources\views/livewire/auth/login.blade.php ENDPATH**/ ?>