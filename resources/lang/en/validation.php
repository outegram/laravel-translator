<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Translator Validation Messages
    |--------------------------------------------------------------------------
    |
    | The following language lines are used by the TranslationParametersRule
    | and TranslationPluralRule validation rules. Publish this file and
    | translate the messages to match your application's locale.
    |
    | Publish with:
    |   php artisan vendor:publish --tag=translator-lang
    |
    */

    'missing_parameters' => 'The translation is missing the following required parameters: :parameters.',

    'plural_variant_mismatch' => 'The plural translation must have :expected pipe-separated variants, but :actual were provided.',

];
