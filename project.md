# Snelstart Package - Project Documentation

## Technical Stack

- **Package name**: `darvis/snelstart`
- **Namespace**: `Darvis\Snelstart`
- **Laravel**: 12.x compatible
- **PHP**: 8.2+

## Structure

```
src/
├── Console/
│   └── Commands/
│       └── TestSnelstartConnection.php   # Artisan command: snelstart:test
├── Facades/
├── Models/
├── Services/
│   ├── SnelstartAPI.php                  # Main API service
│   └── EchoService.php                   # Test service for echo endpoint
├── Standalone/
└── SnelstartServiceProvider.php          # Laravel service provider
config/
└── snelstart.php                         # Package configuration
```

## Services

### SnelstartAPI

Main service for all API communication. Handles automatically:
- OAuth token retrieval via `grant_type=clientkey`
- Token caching (in-memory)
- Subscription key headers
- Error handling

**Configuration via**: `config('snelstart.*')`

### EchoService

Test service for the `/echo/resource` endpoint. Methods:
- `getEchoResource(array $params)` - GET test
- `headEchoResource(array $params)` - HEAD test
- `postEchoResource(array $data)` - POST test

### Standalone\SnelstartAPI

Standalone version without Laravel dependencies. Uses native PHP cURL.
- Constructor accepts config array
- `fromEnv()` factory method for environment variables
- Same API methods as Laravel version

## Container Bindings

| Alias | Class |
|-------|-------|
| `snelstart` | `SnelstartAPI::class` |
| `snelstart.echo` | `EchoService::class` |

## TODO

- [ ] Implement Facades in `src/Facades/`
- [ ] Implement Models in `src/Models/`
