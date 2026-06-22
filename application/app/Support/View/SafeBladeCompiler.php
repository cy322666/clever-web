<?php

namespace App\Support\View;

use ErrorException;
use Illuminate\View\Compilers\BladeCompiler;

class SafeBladeCompiler extends BladeCompiler
{
    public function compile($path = null): void
    {
        if ($path) {
            $this->setPath($path);
        }

        if (is_null($this->cachePath)) {
            return;
        }

        $contents = $this->compileString($this->files->get($this->getPath()));

        if (!empty($this->getPath())) {
            $contents = $this->appendFilePath($contents);
        }

        $this->ensureCompiledDirectoryExists(
            $compiledPath = $this->getCompiledPath($this->getPath())
        );

        if (!$this->files->exists($compiledPath)) {
            $this->files->replace($compiledPath, $contents);

            return;
        }

        try {
            $compiledHash = $this->files->hash($compiledPath, 'xxh128');
        } catch (ErrorException $exception) {
            if (!$this->files->exists($compiledPath)) {
                $this->files->replace($compiledPath, $contents);

                return;
            }

            throw $exception;
        }

        if ($compiledHash !== hash('xxh128', $contents)) {
            $this->files->replace($compiledPath, $contents);
        }
    }
}
