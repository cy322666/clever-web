// import preset from './vendor/filament/support/tailwind.config.preset'

export default {
    // presets: [preset],
    darkMode: 'class',
    content: [
        './app/Filament/**/*.php',
        './resources/views/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/leek/filament-workflows/**/*.blade.php',
    ],
    theme: {extend: {}},
    plugins: [],
}
