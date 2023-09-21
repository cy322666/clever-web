<?php

namespace App\Models\Integrations\Docs;

use App\Models\amoCRM\Field;
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

    public static array $staticVariables = [
        'date',
    ];

    public static function generate(array $variables, TemplateProcessor $doc, array $entities): TemplateProcessor
    {
        foreach ($variables as $variable) {

            if (in_array($variable, static::$staticVariables)) {

                if ($variable == 'date') {

                    $doc->setValue('date', Carbon::now()->format('Y-m-d'));
                }
            } else {

                $field = Field::query()
                    ->where('field_id', $variable)
                    ->first();

                $value = $entities[$field->entity_type]->cf($field->name)->getValue();

                $doc->setValue($variable, $value);

                unset($field, $value);
            }
        }

        return $doc;
    }
}
