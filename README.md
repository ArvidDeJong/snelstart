# Snelstart PHP Package

A standalone PHP package for integration with the **SnelStart B2B-Api v2**. Works seamlessly with Laravel but can also be used in any PHP project without dependencies.

## Installation

```bash
composer require darvis/snelstart
```

The ServiceProvider is automatically registered via Laravel's package auto-discovery.

### Publish Configuration

```bash
php artisan vendor:publish --tag=snelstart-config
```

## Configuration

Add the following variables to your `.env` file:

```env
SNELSTART_BASE_URL=https://b2bapi.snelstart.nl/v2
SNELSTART_TOKEN_URL=https://auth.snelstart.nl/b2b/token
SNELSTART_CLIENT_KEY=your-custom-clientkey
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

| Variable | Description |
|----------|-------------|
| `SNELSTART_BASE_URL` | Base URL of the Snelstart v2 API |
| `SNELSTART_TOKEN_URL` | Token endpoint for authentication |
| `SNELSTART_CLIENT_KEY` | Custom clientkey from SnelStart Web |
| `SNELSTART_SUBSCRIPTION_KEY` | Subscription key from the B2B portal |

## Usage

### Via Dependency Injection

```php
use Darvis\Snelstart\Services\SnelstartAPI;

class MyController extends Controller
{
    public function index(SnelstartAPI $snelstart)
    {
        $relations = $snelstart->getRelaties();
        $articles = $snelstart->getArtikelen();
        
        return view('overview', compact('relations', 'articles'));
    }
}
```

### Via the Container

```php
$snelstart = app(Darvis\Snelstart\Services\SnelstartAPI::class);
$companyInfo = $snelstart->getCompanyInfo();
```

### Available Methods

**Company Info**
```php
$snelstart->getCompanyInfo();
```

**Relations**
```php
$snelstart->getRelaties();
$snelstart->createRelatie(['naam' => 'Company B.V.', ...]);
```

**Articles**
```php
$snelstart->getArtikelen();
```

**Sales Orders**
```php
$snelstart->createVerkooporder([...]);
```

**Generic HTTP Methods**
```php
$snelstart->get('/endpoint', ['query' => 'params']);
$snelstart->post('/endpoint', ['data' => 'here']);
$snelstart->put('/endpoint', ['data' => 'here']);
$snelstart->delete('/endpoint');
```

### EchoService (for testing)

The EchoService can be used to test the API connection:

```php
use Darvis\Snelstart\Services\EchoService;

$echo = app(EchoService::class);

// GET test
$result = $echo->getEchoResource(['param1' => 'test']);

// POST test
$result = $echo->postEchoResource(['key' => 'value']);
```

## Artisan Commands

Test the connection with the Snelstart API:

```bash
php artisan snelstart:test
```

## Standalone Usage (without Laravel)

This package can also be used without Laravel in any PHP project.

### Via Configuration Array

```php
use Darvis\Snelstart\Standalone\SnelstartAPI;

$snelstart = new SnelstartAPI([
    'base_url' => 'https://b2bapi.snelstart.nl/v2',
    'token_url' => 'https://auth.snelstart.nl/b2b/token',
    'client_key' => 'your-custom-clientkey',
    'subscription_key' => 'your-subscription-key',
]);

$companyInfo = $snelstart->getCompanyInfo();
$relations = $snelstart->getRelaties();
```

### Via Environment Variables

```php
use Darvis\Snelstart\Standalone\SnelstartAPI;

// Reads from SNELSTART_BASE_URL, SNELSTART_TOKEN_URL, 
// SNELSTART_CLIENT_KEY, SNELSTART_SUBSCRIPTION_KEY
$snelstart = SnelstartAPI::fromEnv();

$companyInfo = $snelstart->getCompanyInfo();
```

### Requirements for Standalone

- PHP 8.2+
- cURL extension enabled

## Author

**Arvid de Jong**  
info@arvid.nl

## License

MIT License
