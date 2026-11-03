<?php

use App\Ai\Agents\StatementLinesAgent;
use App\Enums\AiFeature;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Statements\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

function statementsEnableAi(bool $statementsFeature = true): void
{
    app(AiSettings::class)->save([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => collect(AiFeature::cases())->mapWithKeys(
            fn (AiFeature $feature) => [$feature->value => $feature === AiFeature::Statements ? $statementsFeature : true]
        )->all(),
    ]);
}

function statementsProseText(): string
{
    return implode("\n", [
        'Estratto conto marzo',
        'Operazione del giorno 4 marzo 2024: pagamento carta presso FARMACIA CENTRALE per un importo di 23,40 euro.',
        'Operazione del giorno 9 marzo 2024: accredito rimborso di 150,00 euro.',
    ]);
}

function statementsUploadProsePdf(User $user): void
{
    app()->instance(PdfTextExtractor::class, new class(statementsProseText()) extends PdfTextExtractor
    {
        public function __construct(private string $text) {}

        public function extract(string $path): ?string
        {
            return $this->text;
        }
    });

    actingAs($user)->post(route('statements.store'), [
        'files' => [UploadedFile::fake()->createWithContent('prose.pdf', '%PDF-1.4 prose')],
    ])->assertSessionHasNoErrors();
}

beforeEach(fn () => Storage::fake('local'));

test('when the parsers find nothing the model reads the text and every line is validated', function () {
    statementsEnableAi();
    StatementLinesAgent::fake([[
        'lines' => [
            ['date' => '2024-03-04', 'description' => 'FARMACIA CENTRALE', 'amount' => -23.40],
            ['date' => '2024-03-09', 'description' => 'Accredito rimborso', 'amount' => 150],
            ['date' => '04/03/2024', 'description' => 'Not an ISO date', 'amount' => -23.40],
            ['date' => '2024-02-31', 'description' => 'Impossible date', 'amount' => 150],
            ['date' => '2024-03-05', 'description' => 'Invented amount', 'amount' => 999.99],
            ['date' => '2024-03-05', 'description' => 'Not a number', 'amount' => 'abc'],
            ['date' => '2024-03-05', 'description' => '', 'amount' => 23.40],
        ],
    ]]);

    $user = User::factory()->create();
    statementsUploadProsePdf($user);

    $statement = $user->statements()->firstOrFail();
    $lines = $statement->lines()->orderBy('posted_on')->get();

    expect($statement->parser)->toBe('ai')
        ->and($lines)->toHaveCount(2)
        ->and($lines->pluck('parsed_by')->unique()->all())->toBe(['ai'])
        ->and($lines->map(fn ($line) => [$line->posted_on->toDateString(), $line->description, $line->amount])->all())->toBe([
            ['2024-03-04', 'FARMACIA CENTRALE', '-23.40'],
            ['2024-03-09', 'Accredito rimborso', '150.00'],
        ])
        ->and($statement->period_start->toDateString())->toBe('2024-03-04');

    StatementLinesAgent::assertPromptedTimes(1);
});

test('the model is not asked while AI is off or its statements switch is off', function (bool $enabled) {
    if ($enabled) {
        statementsEnableAi(false);
    }

    StatementLinesAgent::fake();

    $user = User::factory()->create();
    statementsUploadProsePdf($user);

    $statement = $user->statements()->firstOrFail();

    expect($statement->parser)->toBe('none')
        ->and($statement->lines()->count())->toBe(0)
        ->and($statement->extracted_text)->toContain('FARMACIA CENTRALE');

    StatementLinesAgent::assertNeverPrompted();
})->with([[false], [true]]);

test('the model is not asked when a deterministic parser already read the lines', function () {
    statementsEnableAi();
    StatementLinesAgent::fake();

    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), [
        'files' => [UploadedFile::fake()->createWithContent('plain.csv', "Date,Description,Amount\n2024-03-02,Coffee,-3.50\n")],
    ])->assertSessionHasNoErrors();

    expect($user->statements()->firstOrFail()->parser)->toBe('csv');

    StatementLinesAgent::assertNeverPrompted();
});

test('a model that returns nothing usable leaves the statement stored without lines', function () {
    statementsEnableAi();
    StatementLinesAgent::fake([['lines' => []]]);

    $user = User::factory()->create();
    statementsUploadProsePdf($user);

    $statement = $user->statements()->firstOrFail();

    expect($statement->parser)->toBe('none')
        ->and($statement->lines()->count())->toBe(0)
        ->and($statement->extracted_text)->not->toBeNull();
});
