<?php

use App\Services\Statements\CsvReader;
use App\Services\Statements\GenericCsvParser;
use App\Services\Statements\Normalizer;
use App\Services\Statements\PdfLineParser;
use App\Services\Statements\RevolutCsvParser;
use App\Services\Statements\StatementParser;

function statementsRevolutCsv(): string
{
    return implode("\n", [
        'Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance',
        'CARD_PAYMENT,Current,2024-03-01 08:15:22,2024-03-02 10:00:01,Coffee Shop,-3.50,0.00,EUR,COMPLETED,996.50',
        'TOPUP,Current,2024-03-03 09:00:00,2024-03-03 09:00:05,Top-Up by *1234,200.00,0.00,EUR,COMPLETED,1196.50',
        'CARD_PAYMENT,Current,2024-03-04 12:00:00,,Pending Shop,-9.99,0.00,EUR,PENDING,',
        'EXCHANGE,Current,2024-03-05 12:00:00,2024-03-05 12:00:01,Exchanged to RON,-50.00,0.25,EUR,COMPLETED,1146.25',
    ]);
}

function statementsItalianCsv(): string
{
    return implode("\r\n", [
        'Conto Corrente;IT00X0000000000000000000000',
        'Estratto del periodo;;;',
        'Data;Valuta;Descrizione;Dare;Avere',
        '05/02/2024;05/02/2024;PAGAMENTO POS FARMACIA ROSSI;12,50;',
        '06/02/2024;06/02/2024;STIPENDIO FEBBRAIO;;1.850,00',
        'non una data;;riga rotta;1,00;',
    ]);
}

function statementsPdfText(): string
{
    return implode("\n", [
        'BANCA ESEMPIO S.P.A.            Estratto conto n. 3/2024',
        'Data      Valuta    Descrizione                                   Importo        Saldo',
        'Saldo iniziale al 29/02/2024                                                    1.000,00',
        '01/03/2024  01/03/2024  PAGAMENTO POS SUPERMERCATO ROSSI        -45,30      954,70',
        '05 mar 2024  05/03/2024  ACCREDITO STIPENDIO ACME SRL         1.850,00    2.804,70',
        '2024-03-07  BONIFICO A MARIO BIANCHI AFFITTO               (600.00)',
        'Totale uscite   645,30',
    ]);
}

test('amounts are read in both European and English notation', function (string $input, ?string $expected) {
    expect(Normalizer::amount($input))->toBe($expected);
})->with([
    ['1.234,56', '1234.56'],
    ['1,234.56', '1234.56'],
    ['-12,50', '-12.50'],
    ['+12.5', '12.50'],
    ['(600.00)', '-600.00'],
    ['12.50-', '-12.50'],
    ['12,50 DR', '-12.50'],
    ['12,50 CR', '12.50'],
    ['€ 1 234,56', '1234.56'],
    ['12', '12.00'],
    ['1.234', '1234.00'],
    ['0,00', '0.00'],
    ['abc', null],
    ['', null],
]);

test('dates are read in the formats banks print', function (string $input, bool $dayFirst, ?string $expected) {
    expect(Normalizer::date($input, $dayFirst))->toBe($expected);
})->with([
    ['2024-03-05', true, '2024-03-05'],
    ['2024-03-05 10:11:12', true, '2024-03-05'],
    ['05/03/2024', true, '2024-03-05'],
    ['5.3.24', true, '2024-03-05'],
    ['03/05/2024', false, '2024-03-05'],
    ['05 marzo 2024', true, '2024-03-05'],
    ['15-gen-24', true, '2024-01-15'],
    ['5 settembre 2023', true, '2023-09-05'],
    ['Mar 5, 2024', true, '2024-03-05'],
    ['31/02/2024', true, null],
    ['not a date', true, null],
]);

test('the Revolut parser reads completed rows and turns a fee into its own line', function () {
    $result = app(RevolutCsvParser::class)->parse(statementsRevolutCsv());

    expect($result->parser)->toBe('revolut')
        ->and($result->institution)->toBe('Revolut')
        ->and($result->periodStart())->toBe('2024-03-02')
        ->and($result->periodEnd())->toBe('2024-03-05')
        ->and(array_map(fn ($line) => [$line->date, $line->description, $line->amount, $line->currency], $result->lines))->toBe([
            ['2024-03-02', 'Coffee Shop', '-3.50', 'EUR'],
            ['2024-03-03', 'Top-Up by *1234', '200.00', 'EUR'],
            ['2024-03-05', 'Exchanged to RON', '-50.00', 'EUR'],
            ['2024-03-05', 'Fee: Exchanged to RON', '-0.25', 'EUR'],
        ]);
});

test('the Revolut parser leaves other files alone', function () {
    expect(app(RevolutCsvParser::class)->parse("Date,Description,Amount\n2024-01-01,X,1.00"))->toBeNull();
});

test('the generic parser reads a semicolon CSV with debit and credit columns after a preamble', function () {
    $result = app(GenericCsvParser::class)->parse(statementsItalianCsv());

    expect($result->parser)->toBe('csv')
        ->and(array_map(fn ($line) => [$line->date, $line->description, $line->amount, $line->currency], $result->lines))->toBe([
            ['2024-02-05', 'PAGAMENTO POS FARMACIA ROSSI', '-12.50', null],
            ['2024-02-06', 'STIPENDIO FEBBRAIO', '1850.00', null],
        ]);
});

test('the generic parser detects month-first dates and quoted fields', function () {
    $csv = "Date,Description,Amount,Balance\n01/31/2024,\"AMAZON, MKTP\",-23.99,1000.00\n02/01/2024,PAYROLL ACME,\"2,500.00\",3476.01\n";

    $result = app(GenericCsvParser::class)->parse($csv);

    expect(array_map(fn ($line) => [$line->date, $line->description, $line->amount], $result->lines))->toBe([
        ['2024-01-31', 'AMAZON, MKTP', '-23.99'],
        ['2024-02-01', 'PAYROLL ACME', '2500.00'],
    ]);
});

test('the generic parser reads Windows-1252 files', function () {
    $csv = mb_convert_encoding("Data;Descrizione;Importo\n05/02/2024;CAFFÈ CORSO;-1,20\n", 'Windows-1252', 'UTF-8');

    expect(app(GenericCsvParser::class)->parse($csv)->lines[0]->description)->toBe('CAFFÈ CORSO');
});

test('the generic parser gives up on a file without a recognisable header', function () {
    expect(app(GenericCsvParser::class)->parse("foo,bar\n1,2\n"))->toBeNull();
});

test('the PDF parser reads dated lines with Italian and English dates and ignores balances', function () {
    $result = app(PdfLineParser::class)->parse(statementsPdfText());

    expect($result->parser)->toBe('pdf')
        ->and(array_map(fn ($line) => [$line->date, $line->description, $line->amount], $result->lines))->toBe([
            ['2024-03-01', 'PAGAMENTO POS SUPERMERCATO ROSSI', '-45.30'],
            ['2024-03-05', 'ACCREDITO STIPENDIO ACME SRL', '1850.00'],
            ['2024-03-07', 'BONIFICO A MARIO BIANCHI AFFITTO', '-600.00'],
        ]);
});

test('the PDF parser finds nothing in prose', function () {
    expect(app(PdfLineParser::class)->parse("Gentile cliente,\nla informiamo che le condizioni sono cambiate."))->toBeNull();
});

test('the statement parser tries the deterministic parsers and reports none when nothing reads', function () {
    $parser = app(StatementParser::class);

    expect($parser->parse(statementsRevolutCsv(), true)->parser)->toBe('revolut')
        ->and($parser->parse(statementsItalianCsv(), true)->parser)->toBe('csv')
        ->and($parser->parse(statementsPdfText(), false)->parser)->toBe('pdf')
        ->and($parser->parse('nothing to see here', false)->parser)->toBe('none')
        ->and($parser->parse('nothing to see here', false)->lines)->toBe([]);
});

test('a huge CSV is read only up to the row cap', function () {
    $csv = "Date,Description,Amount\n".str_repeat("2024-01-01,X,1.00\n", CsvReader::MAX_ROWS + 50);

    $rows = CsvReader::rows($csv, null, CsvReader::MAX_ROWS, $truncated);

    expect($rows)->toHaveCount(CsvReader::MAX_ROWS)
        ->and($truncated)->toBeTrue();

    CsvReader::rows("a,b\n1,2\n", null, CsvReader::MAX_ROWS, $small);

    expect($small)->toBeFalse();
});
