<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="<?php echo e(route('admin.users')); ?>" class="p-1 rounded-lg text-slate-500 hover:bg-slate-200 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <h1 class="text-2xl font-bold text-slate-900">Stok Pengguna: <?php echo e($user->name); ?></h1>
            </div>
            <p class="text-sm text-slate-500 mt-1 ml-7"><?php echo e($user->email); ?> &bull; Mode Lihat Saja (Read-Only Admin)</p>
        </div>

        <div>
            <button wire:click="exportExcel" wire:loading.attr="disabled" class="inline-flex items-center space-x-1.5 px-3.5 py-2 bg-slate-700 hover:bg-slate-800 disabled:opacity-75 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                <svg wire:loading.remove wire:target="exportExcel" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <svg wire:loading wire:target="exportExcel" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span wire:loading.remove wire:target="exportExcel">Export Excel Stok Ini</span>
                <span wire:loading wire:target="exportExcel">Mengekspor...</span>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 mb-6 flex flex-col sm:flex-row gap-4 items-center justify-between">
        <div class="w-full sm:w-72 relative">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Cari judul atau deskripsi..." class="w-full pl-3.5 pr-9 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <div wire:loading wire:target="search,statusFilter" class="absolute right-3 top-2.5">
                <svg class="animate-spin h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
        </div>

        <div class="flex items-center space-x-3 w-full sm:w-auto">
            <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Status:</label>
            <select wire:model.live="statusFilter" class="px-3 py-2 text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                <option value="">Semua Status</option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <option value="<?php echo e($status->value); ?>"><?php echo e($status->label()); ?></option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </select>
        </div>
    </div>

    <!-- Table of user's stocks -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="py-3.5 px-6">No</th>
                        <th class="py-3.5 px-6">Nama Barang</th>
                        <th class="py-3.5 px-6">SKU</th>
                        <th class="py-3.5 px-6 text-right">Qty</th>
                        <th class="py-3.5 px-6">Harga</th>
                        <th class="py-3.5 px-6">Status</th>
                        <th class="py-3.5 px-6">Dibuat Pada</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $stocks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $stock): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="py-4 px-6 text-slate-400 text-xs">
                                <?php echo e($stocks->firstItem() + $index); ?>

                            </td>
                            <td class="py-4 px-6 font-semibold text-slate-900">
                                <a href="<?php echo e(route('stocks.show', $stock->id)); ?>" class="hover:text-blue-600"><?php echo e($stock->name); ?></a>
                            </td>
                            <td class="py-4 px-6 text-slate-600 font-mono text-xs"><?php echo e($stock->sku ?? '-'); ?></td>
                            <td class="py-4 px-6 text-right text-slate-800"><?php echo e($stock->quantity); ?> <?php echo e($stock->unit); ?></td>
                            <td class="py-4 px-6 text-slate-600"><?php echo e($stock->price !== null ? 'Rp '.number_format((float) $stock->price, 0, ',', '.') : '-'); ?></td>
                            <td class="py-4 px-6">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full border <?php echo e($stock->status->badgeClass()); ?>">
                                    <?php echo e($stock->status->label()); ?>

                                </span>
                            </td>
                            <td class="py-4 px-6 text-slate-500 text-xs">
                                <?php echo e($stock->created_at ? $stock->created_at->format('d M Y H:i') : '-'); ?>

                            </td>
                        </tr>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-500">
                                Pengguna ini belum memiliki stok.
                            </td>
                        </tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-slate-100">
            <?php echo e($stocks->links()); ?>

        </div>
    </div>
</div>

<?php /**PATH C:\Users\ASUS\Downloads\pabwe-p7-stock\resources\views/livewire/admin/admin-user-stock-list.blade.php ENDPATH**/ ?>