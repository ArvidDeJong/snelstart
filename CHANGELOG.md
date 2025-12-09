# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-09

### Added

- **SnelstartAPI Service** - Main API client for SnelStart B2B-Api v2
  - OAuth token authentication with automatic refresh
  - Company info retrieval (`getCompanyInfo`)
  - Relations management (`getRelaties`, `createRelatie`)
  - Articles retrieval (`getArtikelen`)
  - Sales orders (`createVerkooporder`)
  - Generic HTTP methods (`get`, `post`, `put`, `delete`, `head`)

- **EchoService** - Test service for API connection verification
  - GET, HEAD, and POST requests to echo endpoint
  - Response time measurement
  - Detailed success/error responses

- **Standalone Support** - Use without Laravel
  - `Darvis\Snelstart\Standalone\SnelstartAPI` class
  - Native PHP cURL implementation
  - Configuration via array or environment variables
  - `fromEnv()` factory method

- **Laravel Integration**
  - Auto-discovery ServiceProvider
  - Publishable configuration file
  - Dependency injection support
  - Container bindings (`snelstart`, `snelstart.echo`)

- **Artisan Command**
  - `snelstart:test` - Test API connection

### Configuration

- `SNELSTART_BASE_URL` - API base URL
- `SNELSTART_TOKEN_URL` - Authentication endpoint
- `SNELSTART_CLIENT_KEY` - Custom client key
- `SNELSTART_SUBSCRIPTION_KEY` - B2B portal subscription key
