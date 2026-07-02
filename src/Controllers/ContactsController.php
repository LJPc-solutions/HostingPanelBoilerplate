<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\Api\ApiException;
use Panel\App;
use Panel\Response;

final class ContactsController
{
    /** @var list<string> 2-letter country codes; extend this list as more markets are supported */
    private const COUNTRIES = ['NL', 'BE', 'DE', 'FR', 'GB', 'US'];

    public function __construct(private readonly App $app)
    {
    }

    public function index(array $params): Response
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM contacts WHERE user_id = ? ORDER BY name');
        $stmt->execute([$this->user()['id']]);

        return Response::html($this->app->view->render('contacts/index', [
            'title' => 'Contacts',
            'contacts' => $stmt->fetchAll(),
        ]));
    }

    public function showCreate(array $params): Response
    {
        return Response::html($this->app->view->render('contacts/form', [
            'title' => 'New contact',
            'contact' => null,
            'old' => [],
            'countries' => self::COUNTRIES,
            'action' => '/contacts/new',
        ]));
    }

    public function create(array $params): Response
    {
        $data = $this->validate($_POST);
        if (is_string($data)) {
            return Response::html($this->app->view->render('contacts/form', [
                'title' => 'New contact',
                'contact' => null,
                'old' => $_POST,
                'countries' => self::COUNTRIES,
                'action' => '/contacts/new',
                'error' => $data,
            ]));
        }

        try {
            $handle = $this->app->api->createContact($data);
        } catch (ApiException $e) {
            return Response::html($this->app->view->render('contacts/form', [
                'title' => 'New contact',
                'contact' => null,
                'old' => $_POST,
                'countries' => self::COUNTRIES,
                'action' => '/contacts/new',
                'error' => $e->getMessage(),
            ]));
        }

        $stmt = $this->app->pdo->prepare('INSERT INTO contacts (user_id, handle, name, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$this->user()['id'], $handle, $data['name'], date('Y-m-d H:i:s')]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contact created.'];

        return Response::redirect('/contacts');
    }

    public function showEdit(array $params): Response
    {
        $row = $this->ownedContact((int) $params['id']);
        if ($row === null) {
            return $this->notFound();
        }

        $contact = $this->app->api->getContact($row['handle']);

        return Response::html($this->app->view->render('contacts/form', [
            'title' => 'Edit contact',
            'contact' => $contact,
            'old' => [],
            'countries' => self::COUNTRIES,
            'action' => "/contacts/{$row['id']}/edit",
        ]));
    }

    public function edit(array $params): Response
    {
        $row = $this->ownedContact((int) $params['id']);
        if ($row === null) {
            return $this->notFound();
        }

        $data = $this->validate($_POST);
        if (is_string($data)) {
            return Response::html($this->app->view->render('contacts/form', [
                'title' => 'Edit contact',
                'contact' => null,
                'old' => $_POST,
                'countries' => self::COUNTRIES,
                'action' => "/contacts/{$row['id']}/edit",
                'error' => $data,
            ]));
        }

        try {
            $this->app->api->updateContact($row['handle'], $data);
        } catch (ApiException $e) {
            return Response::html($this->app->view->render('contacts/form', [
                'title' => 'Edit contact',
                'contact' => null,
                'old' => $_POST,
                'countries' => self::COUNTRIES,
                'action' => "/contacts/{$row['id']}/edit",
                'error' => $e->getMessage(),
            ]));
        }

        $stmt = $this->app->pdo->prepare('UPDATE contacts SET name = ? WHERE id = ?');
        $stmt->execute([$data['name'], $row['id']]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contact updated.'];

        return Response::redirect('/contacts');
    }

    /** @return array{name: string, organization: ?string, address: string, postal_code: string, city: string, country: string, email: string, voice: string}|string data on success, error message on failure */
    private function validate(array $input): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $organization = trim((string) ($input['organization'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $postalCode = trim((string) ($input['postal_code'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $email = trim((string) ($input['email'] ?? ''));
        $voice = trim((string) ($input['voice'] ?? ''));

        if ($name === '' || $address === '' || $postalCode === '' || $city === '') {
            return 'Please fill in all required fields.';
        }
        if (!in_array($country, self::COUNTRIES, true)) {
            return 'Please choose a valid country.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        if (!preg_match('/^\+[0-9]{6,15}$/', $voice)) {
            return 'Please enter a valid phone number, e.g. +31612345678.';
        }

        return [
            'name' => $name,
            'organization' => $organization !== '' ? $organization : null,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'country' => $country,
            'email' => $email,
            'voice' => $voice,
        ];
    }

    private function ownedContact(int $id): ?array
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM contacts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->user()['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function notFound(): Response
    {
        return Response::html($this->app->view->render('404', ['title' => 'Page not found']), 404);
    }

    private function user(): array
    {
        return $this->app->auth->user();
    }
}
