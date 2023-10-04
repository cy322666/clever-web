<?php

namespace App\Services\Doc;

use App\Models\amoCRM\Field;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mackey\Yandex\Disk;

class DiskService
{
    public static function getLocalPath()
    {
        return Config::get('services.yandex.local_storage_path');
    }

    public static function checkYandexPath(string $uploadPath, Disk $disk)
    {
        $resource = $disk->resource($uploadPath);

        if (!$resource->has()) {

            $previous_value = '';

            foreach (explode('/', $uploadPath) as $value) {

                $previous_value .= $value.'/';

                if (!$disk->resource($previous_value)->has()) {

                    $dir = $disk->resource($previous_value);
                    $dir->create();
                }
            }
        }
    }

    public static function checkLocalDirectory(string $localPath): void
    {
        if (!File::exists($localPath))

            File::makeDirectory($localPath);
    }
}
