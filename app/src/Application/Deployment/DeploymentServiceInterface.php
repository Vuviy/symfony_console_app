<?php

declare(strict_types=1);

namespace App\Application\Deployment;

use App\Application\DTO\DeploymentReport;
use App\Application\DTO\DeploymentRequest;

interface DeploymentServiceInterface
{
    /**
     * Execute a deployment pipeline from a structured request.
     */
    public function deploy(DeploymentRequest $request): DeploymentReport;
}