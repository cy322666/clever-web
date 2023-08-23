{{--<x-filament::widget>--}}
{{--    <x-filament::card>--}}
<x-filament::widget class="filament-filament-info-widget">
    <x-filament::card class="relative">
{{--        <div class="relative h-12 flex flex-col justify-center items-center space-y-2">--}}

                <script
                    class="amocrm_oauth"
                    charset="utf-8"
                    data-client-id="{{ config('services.amocrm.client_id') }}"
                    data-name="{{ config('services.amocrm.app_name') }}"
                    data-description="{{ config('services.amocrm.description') }}"
                    data-redirect_uri="{{ config('services.amocrm.redirect_uri') }}"
                    data-secrets_uri="{{ config('services.amocrm.secrets_uri') }}"
                    data-logo=""
                    data-scopes="crm, notifications"
                    data-title="Подключить платформу"
                    data-compact="false"
                    data-class-name="amo-connect"
                    data-color="default"
                    data-state="hello"
                    data-error-callback="functionName"
                    data-mode="popup"
                    src="https://www.amocrm.ru/auth/button.min.js">
                </script>

{{--        </div>--}}
    </x-filament::card>
</x-filament::widget>
