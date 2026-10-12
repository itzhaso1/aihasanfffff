<button {{ $attributes->merge(['type' => 'submit', 'class' => 'hs-btn-primary']) }}>
    {{ $slot }}
</button>
