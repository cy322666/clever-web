<?php

use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calculator_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('formulas')->nullable();
            $table->boolean('active')->default(false);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        if (class_exists(App::class) && class_exists(User::class)) {
            $service = app(IntegrationProvisioningService::class);

            User::query()->select(['id'])->chunkById(200, function ($users) use ($service): void {
                foreach ($users as $user) {
                    $service->syncCatalogForUser($user);
                }
            });
        }

        $this->seedWidgetCatalog();
    }

    public function down(): void
    {
        if (Schema::hasTable('widgets')) {
            DB::table('widgets')->where('slug', 'calculator-fields')->delete();
        }

        Schema::dropIfExists('calculator_settings');
    }

    private function seedWidgetCatalog(): void
    {
        if (!Schema::hasTable('widgets')) {
            return;
        }

        $now = now();

        DB::table('widgets')->updateOrInsert(
            ['slug' => 'calculator-fields'],
            [
                'title' => 'Калькулятор полей',
                'excerpt' => 'Расчет значений по формулам и автоматическая запись результата в поля amoCRM.',
                'description' => '<p>Калькулятор полей помогает считать маржу, скидки, стоимость проекта, бонусы и другие показатели прямо в amoCRM. Формулы можно запускать из конструктора процессов и записывать результат в выбранное поле сделки, контакта или компании.</p><ul><li>формулы с переменными процессов и webhook;</li><li>арифметика, скобки, округление и базовые функции;</li><li>запись результата в системные и пользовательские поля;</li><li>приоритеты и группы формул в настройках виджета.</li></ul>',
                'logo_url' => '/logo/widgets/clever_mini_logo_20.png',
                'tags' => json_encode(['amoCRM', 'Формулы', 'Автоматизация'], JSON_UNESCAPED_UNICODE),
                'pricing_type' => 'paid',
                'price_from_rub' => 1990,
                'trial_days' => 14,
                'installs_count' => 0,
                'is_featured' => true,
                'is_published' => true,
                'published_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        if (!Schema::hasTable('widget_categories') || !Schema::hasTable('widget_category')) {
            return;
        }

        DB::table('widget_categories')->updateOrInsert(
            ['slug' => 'automation'],
            [
                'name' => 'Автоматизация',
                'sort' => 20,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $widgetId = DB::table('widgets')->where('slug', 'calculator-fields')->value('id');
        $categoryId = DB::table('widget_categories')->where('slug', 'automation')->value('id');

        if ($widgetId && $categoryId) {
            DB::table('widget_category')->updateOrInsert([
                'widget_id' => $widgetId,
                'widget_category_id' => $categoryId,
            ]);
        }
    }
};
