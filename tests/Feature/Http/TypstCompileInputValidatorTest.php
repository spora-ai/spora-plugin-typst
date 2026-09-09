<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Http\CompileInputs;
use Spora\Plugins\Typst\Http\CompileInputValidation;
use Spora\Plugins\Typst\Http\TypstCompileInputValidator;
use Symfony\Component\HttpFoundation\Request;

const VALIDATOR_PATH = '/api/v1/typst/compile';
const VALIDATOR_JSON_MIME = 'application/json';
const VALIDATOR_HELLO_SOURCE = '= Hello';

function validatorCompileRequest(array $body): Request
{
    return Request::create(
        VALIDATOR_PATH,
        'POST',
        server: ['CONTENT_TYPE' => VALIDATOR_JSON_MIME],
        content: json_encode($body),
    );
}

it('parses a full compile body into a CompileInputs value object', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'name'   => 'letter',
        'format' => 'pdf',
        'page'   => 0,
        'ppi'    => 144,
    ]));

    expect($inputs)->toBeInstanceOf(CompileInputs::class);
    expect($inputs->source)->toBe(VALIDATOR_HELLO_SOURCE);
    expect($inputs->name)->toBe('letter.typ');
    expect($inputs->format)->toBe('pdf');
    expect($inputs->page)->toBe(0);
    expect($inputs->ppi)->toBe(144.0);
});

it('defaults the filename to playground.typ when name is omitted', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
    ]));

    expect($inputs->name)->toBe('playground.typ');
});

it('defaults the format to pdf when format is omitted', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
    ]));

    expect($inputs->format)->toBe('pdf');
});

it('normalises uppercase and mixed-case format values to lowercase', function (): void {
    $validator = new TypstCompileInputValidator();

    foreach (['PDF', 'Pdf', '  PNG ', 'SVG'] as $value) {
        $inputs = $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'format' => $value,
        ]));
        expect($inputs->format)->toBe(strtolower(trim($value)));
    }
});

it('keeps a filename that already ends with .typ unchanged', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'name'   => 'letter.typ',
    ]));

    expect($inputs->name)->toBe('letter.typ');
});

it('trims surrounding whitespace from a filename', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'name'   => '  letter  ',
    ]));

    expect($inputs->name)->toBe('letter.typ');
});

it('treats an empty or whitespace-only name as the default filename', function (): void {
    $validator = new TypstCompileInputValidator();

    foreach (['', '   '] as $value) {
        $inputs = $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'name'   => $value,
        ]));
        expect($inputs->name)->toBe('playground.typ');
    }
});

it('clamps a negative page to 0', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'format' => 'png',
        'page'   => -3,
    ]));

    expect($inputs->page)->toBe(0);
});

it('leaves page null when not provided', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
    ]));

    expect($inputs->page)->toBeNull();
});

it('clamps ppi below 36 up to 36', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'format' => 'png',
        'ppi'    => 12,
    ]));

    expect($inputs->ppi)->toBe(36.0);
});

it('clamps ppi above 600 down to 600', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
        'format' => 'png',
        'ppi'    => 1200,
    ]));

    expect($inputs->ppi)->toBe(600.0);
});

it('leaves ppi null when not provided', function (): void {
    $validator = new TypstCompileInputValidator();
    $inputs = $validator->parseCompileInputs(validatorCompileRequest([
        'source' => VALIDATOR_HELLO_SOURCE,
    ]));

    expect($inputs->ppi)->toBeNull();
});

it('rejects invalid JSON with the 400 INVALID_JSON envelope', function (): void {
    $validator = new TypstCompileInputValidator();
    $req = Request::create(
        VALIDATOR_PATH,
        'POST',
        server: ['CONTENT_TYPE' => VALIDATOR_JSON_MIME],
        content: '{ this is not json',
    );

    $caught = null;
    try {
        $validator->parseCompileInputs($req);
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(400);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

it('rejects a missing source with the 422 VALIDATION_ERROR envelope', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest([]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('source');
});

it('rejects a whitespace-only source with the 422 envelope', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest(['source' => '   ']));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('rejects a non-string source with the 422 envelope', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest(['source' => ['nested' => 'array']]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('rejects an unknown format with the 422 envelope and echoes the bad value', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'format' => 'docx',
        ]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('docx');
});

it('rejects a non-string filename with the 422 envelope', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'name'   => ['nested' => 'array'],
        ]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('rejects a filename that escapes the principal directory (path traversal)', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'name'   => '../../etc/passwd',
        ]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('illegal characters');
});

it('rejects a filename that contains a NUL byte', function (): void {
    $validator = new TypstCompileInputValidator();

    $caught = null;
    try {
        $validator->parseCompileInputs(validatorCompileRequest([
            'source' => VALIDATOR_HELLO_SOURCE,
            'name'   => "letter.typ\0.evil",
        ]));
    } catch (CompileInputValidation $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->response->getStatusCode())->toBe(422);
    $body = json_decode((string) $caught->response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});
