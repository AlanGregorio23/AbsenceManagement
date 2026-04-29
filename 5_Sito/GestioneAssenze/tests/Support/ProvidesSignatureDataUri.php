<?php

namespace Tests\Support;

trait ProvidesSignatureDataUri
{
    protected function validPngSignatureDataUri(): string
    {
        return 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8B9f8AAAAASUVORK5CYII=';
    }

    protected function validSignatureDataUri(): string
    {
        return $this->validPngSignatureDataUri();
    }

    protected function validSignatureDataUrl(): string
    {
        return $this->validPngSignatureDataUri();
    }
}
