<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant aliases
    |--------------------------------------------------------------------------
    |
    | Names that are one and the same merchant on a bank statement. The recurring
    | payment suggestions fold every alias into its canonical name before grouping
    | the charges, so the same service typed two ways is one subscription. A whole
    | description has to match (case, accents and punctuation do not matter):
    |
    |   ['elevenlabs' => ['11labs', 'eleven labs']]
    |
    */

    'merchant_aliases' => [
        'elevenlabs' => ['11labs', 'eleven labs'],
    ],

];
