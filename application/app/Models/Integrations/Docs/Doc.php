<?php

namespace App\Models\Integrations\Docs;

use App\Http\Requests\Api\GetCourse\PaymentRequest;
use App\Models\amoCRM\Field;
use App\Services\Doc\DefaultFormatting;
use App\Services\Doc\FormatService;
use App\Services\Doc\StandardFormatting;
use App\Services\Doc\StaticFormatting;
use App\Services\Doc\TransformFormatting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpWord\TemplateProcessor;

class Doc extends Model
{
    use HasFactory;

    protected $table = 'docs_transactions';

    protected $fillable = [
        'user_id',
        'body',
        'filename',
        'lead_id',
        'status',
        'doc_id',
    ];

    /**
     * обычные поля
     * статические
     * обычные преобразованные
     * проверка от сложного к простому
     *
     * @param array $variables
     * @param TemplateProcessor $doc
     * @param array $entities
     * @return TemplateProcessor
     */
    public static function generate(array $variables, TemplateProcessor $doc, array $entities): TemplateProcessor
    {
        // переменные из шаблона
        foreach ($variables as $variable) {

            $value = null;
            // 123123#date|Y-m-d - поле амо + форматирование
            if (str_contains($variable, '#')) {

                // значение из amoCRM
                $value = FormatService::getValue(FormatService::getFieldId($variable), $entities);

                // transform
                $valueFormat = TransformFormatting::matchTypeAndFormat($variable, $value);

            } elseif(str_contains($variable, '|')) {

                // static
                $valueFormat = StaticFormatting::matchTypeAndFormat($variable);

            } elseif(str_contains($variable, '@'))
                // standard
                $value = FormatService::getValueStandard(str_replace('@', '', $variable), $entities);
            else
                //простое поле
                $value = FormatService::getValue((int)$variable, $entities);

            $doc->setValue($variable, $valueFormat ?? $value);

            unset($variable);
            unset($valueFormat);
            unset($value);
        }

        return $doc;
    }

    public function getFileName(?string $template, array $entities) : string
    {
        $fileName = '';

        foreach (explode('+', $template) as $item) {

            $fileName .= FormatService::getValue((int)$item, $entities).'-';
        }

        return $fileName.Carbon::now()->format('Y-m-d');
    }
}
