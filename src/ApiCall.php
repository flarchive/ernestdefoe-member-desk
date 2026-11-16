<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\Api\JsonApi;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * Calls another resource's API endpoint as the acting staff member, so its owner (core, gdpr,
 * suspend, fof/ban-ips) runs its own permission checks, validation, hooks and events exactly as
 * for a request from its own UI.
 *
 * 🚨 JsonApi::process() never loads the model or checks the endpoint's permission for an
 * update/delete, so this sends a real request through handle(), with a path relative to the API
 * (as the router hands it to JsonApi).
 */
class ApiCall
{
    public function __construct(protected JsonApi $api)
    {
    }

    /**
     * @param class-string $resource
     * @param array<string, mixed> $body
     */
    public function send(User $actor, string $resource, string $endpoint, string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequest([], [], $path, $method))
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withHeader('Accept', 'application/vnd.api+json');

        return $this->api->forResource($resource)->forEndpoint($endpoint)->handle(RequestUtil::withActor($request, $actor));
    }
}
