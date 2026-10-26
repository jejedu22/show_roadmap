<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\Person;
use App\Entity\Show;
use App\Security\Access;
use App\Service\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class CompanyController extends ApiController
{
    #[Route('/api/companies', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $me = $this->me();
        $companies = $me->isAdmin ? $this->em->getRepository(Company::class)->findBy([], ['name' => 'ASC']) : $me->companies->toArray();
        usort($companies, fn ($a, $b) => strcasecmp($a->name, $b->name));

        return $this->json(array_map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'showCount' => $c->shows->count()], $companies));
    }

    #[Route('/api/companies', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = new Company(Input::str($this->body($request), 'name') ?: 'Nouvelle compagnie');
        $this->em->persist($company);
        $this->me()->companies->add($company);

        return $this->savedCompany($company);
    }

    #[Route('/api/companies/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->presenter->company($this->company($id)));
    }

    #[Route('/api/companies/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $company = $this->company($id);
        $d = $this->body($request);
        $company->name = Input::str($d, 'name') ?: $company->name;
        $company->notes = Input::text($d, 'notes');

        return $this->savedCompany($company);
    }

    /** Suppression définitive (administrateurs) : il faut renvoyer le nom exact de la compagnie. */
    #[Route('/api/companies/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if (!$this->me()->isAdmin) {
            throw new AccessDeniedHttpException('Seul un administrateur peut supprimer une compagnie.');
        }
        $company = $this->company($id);
        if (Input::str($this->body($request), 'confirmName') !== $company->name) {
            throw new BadRequestHttpException('Pour confirmer, saisissez exactement le nom de la compagnie.');
        }
        $this->em->remove($company);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    // ---- Spectacles ----

    #[Route('/api/companies/{id<\d+>}/shows', methods: ['POST'])]
    public function createShow(int $id, Request $request): JsonResponse
    {
        $company = $this->company($id);
        $d = $this->body($request);
        $show = new Show($company, Input::str($d, 'title') ?: throw new BadRequestHttpException('Titre obligatoire'));
        $show->description = Input::text($d, 'description');
        $this->em->persist($show);

        return $this->savedCompany($company);
    }

    #[Route('/api/shows/{id<\d+>}', methods: ['PUT'])]
    public function updateShow(int $id, Request $request): JsonResponse
    {
        $show = $this->showOr404($id);
        $d = $this->body($request);
        $show->title = Input::str($d, 'title') ?: $show->title;
        $show->description = Input::text($d, 'description');

        return $this->savedCompany($show->company);
    }

    #[Route('/api/shows/{id<\d+>}', methods: ['DELETE'])]
    public function deleteShow(int $id): JsonResponse
    {
        $show = $this->showOr404($id);
        if (!$show->events->isEmpty()) {
            throw new ConflictHttpException('Supprimez d\'abord les dates de ce spectacle.');
        }
        $this->em->remove($show);

        return $this->savedCompany($show->company);
    }

    // ---- Annuaire ----

    #[Route('/api/companies/{id<\d+>}/people', methods: ['POST'])]
    public function createPerson(int $id, Request $request): JsonResponse
    {
        $company = $this->company($id);
        $person = new Person($company);
        $this->fillPerson($person, $this->body($request));
        $this->em->persist($person);

        return $this->savedCompany($company);
    }

    #[Route('/api/people/{id<\d+>}', methods: ['PUT'])]
    public function updatePerson(int $id, Request $request): JsonResponse
    {
        $person = $this->personOr404($id);
        $this->fillPerson($person, $this->body($request));

        return $this->savedCompany($person->company);
    }

    #[Route('/api/people/{id<\d+>}', methods: ['DELETE'])]
    public function deletePerson(int $id): JsonResponse
    {
        $person = $this->personOr404($id);
        $this->em->remove($person);

        return $this->savedCompany($person->company);
    }

    // ---- Création d'une date ----

    #[Route('/api/shows/{id<\d+>}/events', methods: ['POST'])]
    public function createEvent(int $id, Request $request): JsonResponse
    {
        $show = $this->showOr404($id);
        $d = $this->body($request);
        $first = Input::date($d, 'firstDate') ?? throw new BadRequestHttpException('Date de 1re représentation obligatoire');
        $last = Input::date($d, 'lastDate') ?? $first;
        if ($last < $first) {
            throw new BadRequestHttpException('La dernière date précède la première');
        }
        $event = new Event($show, Input::str($d, 'venue') ?: throw new BadRequestHttpException('Lieu obligatoire'), $first, $last);
        $event->city = Input::str($d, 'city', 120);
        $event->performances = $first->diff($last)->days + 1;
        $event->departure = $last;
        $this->em->persist($event);

        return $this->savedEvent($event);
    }

    private function showOr404(int $id): Show
    {
        $show = $this->em->find(Show::class, $id) ?? throw new NotFoundHttpException('Spectacle introuvable');
        Access::company($this->me(), $show->company);

        return $show;
    }

    private function personOr404(int $id): Person
    {
        $person = $this->em->find(Person::class, $id) ?? throw new NotFoundHttpException('Personne introuvable');
        Access::company($this->me(), $person->company);

        return $person;
    }

    private function fillPerson(Person $p, array $d): void
    {
        $p->lastName = Input::str($d, 'lastName', 120) ?: throw new BadRequestHttpException('Le nom est obligatoire');
        $p->firstName = Input::str($d, 'firstName', 120);
        $p->function = Input::str($d, 'function', 120);
        $p->phone = Input::str($d, 'phone', 60);
        $p->email = Input::str($d, 'email');
        $p->diet = Input::str($d, 'diet');
        $p->homeCity = Input::str($d, 'homeCity', 120);
    }
}
