<button {{ $attributes->merge(['type' => 'submit', 'class' => 'hs-btn-danger']) }}>
    {{ $slot }}
</button>
