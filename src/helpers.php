<?php

function __(string $key, array $replace = []): string
{
    return \App\Translator::trans($key, $replace);
}
