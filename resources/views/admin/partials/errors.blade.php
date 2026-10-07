@if($errors->any())
    <div class="canvas-card fade-in" style="margin-bottom: 40px; padding: 24px; border-color: rgba(248, 113, 113, 0.3); background: rgba(248, 113, 113, 0.05);">
        <div style="display: flex; flex-direction: column; gap: 12px; color: var(--danger); font-weight: 700; font-size: 0.9rem;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                DANGER: Configuration Failure
            </div>
            <ul style="list-style: none; padding-left: 28px; font-weight: 600; font-size: 0.85rem;">
                @foreach($errors->all() as $error)
                    <li>• {{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
