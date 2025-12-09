<?php

namespace Darvis\Snelstart\Services;

use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Support\Facades\Log;

class EchoService
{
    protected SnelstartAPI $apiService;

    public function __construct(SnelstartAPI $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * GET request to echo/resource endpoint with query parameters
     */
    public function getEchoResource(array $params = []): array
    {
        try {
            $startTime = microtime(true);
            
            // Use default parameter if no params are provided
            $queryParams = $params ?: ['param1' => 'sample'];
            
            $response = $this->apiService->get('/echo/resource', $queryParams);
            
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => 'Echo resource GET successful',
                'response_time_ms' => $responseTime,
                'timestamp' => now()->toISOString(),
                'query_params' => $queryParams,
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('Snelstart Echo Resource GET failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Echo resource GET failed',
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'timestamp' => now()->toISOString()
            ];
        }
    }

    /**
     * HEAD request to echo/resource endpoint
     */
    public function headEchoResource(array $params = []): array
    {
        try {
            $startTime = microtime(true);

            $queryParams = $params ?: ['param1' => 'sample'];

            $response = $this->apiService->head('/echo/resource', $queryParams);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => 'Echo resource HEAD successful',
                'response_time_ms' => $responseTime,
                'timestamp' => now()->toISOString(),
                'query_params' => $queryParams,
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('Snelstart Echo Resource HEAD failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Echo resource HEAD failed',
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'timestamp' => now()->toISOString()
            ];
        }
    }

    /**
     * POST request to echo/resource endpoint
     */
    public function postEchoResource(array $data = []): array
    {
        try {
            $startTime = microtime(true);
            
            // Use sample data if no data is provided
            $postData = $data ?: [
                'vehicleType' => 'train',
                'maxSpeed' => 125,
                'avgSpeed' => 90,
                'speedUnit' => 'mph'
            ];
            
            $response = $this->apiService->post('/echo/resource', $postData);
            
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => 'Echo resource POST successful',
                'response_time_ms' => $responseTime,
                'timestamp' => now()->toISOString(),
                'request_data' => $postData,
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('Snelstart Echo Resource POST failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Echo resource POST failed',
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'timestamp' => now()->toISOString()
            ];
        }
    }
}
