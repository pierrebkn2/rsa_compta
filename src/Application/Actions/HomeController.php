<?php

namespace App\Application\Actions;

use Psr\Container\ContainerInterface;

class HomeController
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function home($request, $response, $args)
    {
        $this->container->get('view')->render($response, 'home.html.twig', [
          'name' => 'John Doe'
        ]);
        return $response;
    }

    public function selectYear($request, $response, $args)
    {
        // Read the files names in the var directory and extract the years from the file names
        // @phpstan-ignore constant.notFound
        $files = scandir(APP_ROOT . '/var');
        $years = [];
        foreach ($files as $file) {
            if (preg_match('/db_(\d{4})\.sqlite/', $file, $matches)) {
                $years[] = $matches[1];
            }
        }
        $this->container->get('view')->render($response, 'select_year.html.twig', [
            'years' => $years
        ]);
        return $response;
    }

    public function pickYear($request, $response, $args)
    {
        $data = $request->getParsedBody();
        $year = $data['year'] ?? null;
        if ($year) {
            // Save the current year in /var/current_year.txt
            // @phpstan-ignore constant.notFound
            file_put_contents(APP_ROOT . '/var/current_year.txt', $year);
        }
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
