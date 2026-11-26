import { defineConfig } from 'vite'
import laravel, { refreshPaths } from 'laravel-vite-plugin'

export default defineConfig({
    build: {
        manifest: 'manifest.json',
        target: ['es2020', 'edge88', 'firefox78', 'chrome87', 'safari14'],
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament-workflows.css',
                'resources/js/app.js',
                'resources/js/workflows.js',
            ],
            refresh: [
                ...refreshPaths,
                'app/Filament/**',
                'app/Forms/Components/**',
                'app/Livewire/**',
                'app/Infolists/Components/**',
                'app/Providers/Filament/**',
                'app/Tables/Columns/**',
            ],
        }),
    ],
})
