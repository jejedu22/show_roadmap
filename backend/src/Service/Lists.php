<?php

namespace App\Service;

use App\Entity\ListItem;
use App\Service\Import\Sheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Listes paramétrées : fonctions des personnes et intitulés de planning (choix proposés, contrôle des saisies, rapprochement à l'import). */
class Lists
{
    /** @var array<string, list<string>>|null */
    private ?array $cache = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array<string, list<string>> libellés par liste, dans l'ordre choisi */
    public function all(): array
    {
        if (null === $this->cache) {
            $this->cache = array_fill_keys(array_keys(ListItem::KINDS), []);
            foreach ($this->em->getRepository(ListItem::class)->findBy([], ['position' => 'ASC', 'label' => 'ASC']) as $item) {
                $this->cache[$item->kind][] = $item->label;
            }
        }

        return $this->cache;
    }

    /** @return list<string> */
    public function entries(string $kind): array
    {
        return $this->all()[$kind] ?? [];
    }

    /** Remplace une liste (ordre compris). Les données déjà saisies ne changent pas : une valeur retirée devient « hors liste ». */
    public function replace(string $kind, array $labels): array
    {
        $clean = [];
        foreach ($labels as $label) {
            $label = trim(preg_replace('/\s+/u', ' ', (string) $label));
            if ('' !== $label && mb_strlen($label) <= 120) {
                $clean[Sheet::norm($label)] ??= $label; // pas de doublon à la casse ou aux accents près
            }
        }
        if (count($clean) > 500) {
            throw new BadRequestHttpException('500 entrées au plus par liste.');
        }
        $existing = [];
        foreach ($this->em->getRepository(ListItem::class)->findBy(['kind' => $kind]) as $item) {
            $existing[Sheet::norm($item->label)] = $item;
        }
        $position = 0;
        foreach ($clean as $key => $label) {
            $item = $existing[$key] ?? null;
            unset($existing[$key]);
            if ($item) {
                [$item->label, $item->position] = [$label, ++$position];
            } else {
                $this->em->persist(new ListItem($kind, $label, ++$position));
            }
        }
        array_map(fn ($item) => $this->em->remove($item), $existing);
        $this->em->flush();
        $this->cache = null;

        return $this->entries($kind);
    }

    /** Ajoute des libellés en fin de liste (import) ; ignore ceux déjà présents. */
    public function append(string $kind, array $labels): void
    {
        $this->replace($kind, [...$this->entries($kind), ...$labels]);
    }

    /**
     * Saisie autorisée : vide, inchangée (ancienne valeur hors liste conservée) ou présente dans la liste.
     * Renvoie le libellé de la liste (casse et accents de la liste).
     */
    public function check(string $kind, string $value, string $current = ''): string
    {
        if ('' === $value || $value === $current) {
            return $value;
        }
        foreach ($this->entries($kind) as $label) {
            if (Sheet::norm($label) === Sheet::norm($value)) {
                return $label;
            }
        }
        throw new BadRequestHttpException(sprintf('« %s » ne fait pas partie de la liste « %s » (Administration → Listes).', $value, ListItem::KINDS[$kind]));
    }

    /**
     * Rapproche une valeur importée d'une entrée de liste : identique (casse, accents), ou — pour le planning —
     * commençant par une entrée (« Représentation #1 - Place Kléber » → Représentation + « #1 - Place Kléber »).
     *
     * @return array{label: string, detail: string}|null
     */
    public function match(string $kind, string $value): ?array
    {
        $norm = self::key($value);
        $best = null;
        foreach ($this->entries($kind) as $label) {
            $l = self::key($label);
            if ($l === $norm) {
                return ['label' => $label, 'detail' => ''];
            }
            if ('schedule' === $kind && str_starts_with($norm, $l) && !preg_match('/^[\p{L}\p{N}]/u', substr($norm, strlen($l)))
                && (!$best || mb_strlen($label) > mb_strlen($best))) {
                $best = $label;
            }
        }

        return $best ? ['label' => $best, 'detail' => self::rest($value, $best)] : null;
    }

    /** Forme de comparaison : sans casse, accents ni tirets (« Petit déjeuner » = « Petit-déjeuner »). */
    public static function key(string $s): string
    {
        return str_replace('-', ' ', Sheet::norm($s));
    }

    /** Ce qui suit l'intitulé, sans séparateur de tête : « Pause / danseurs au plateau » → « danseurs au plateau ». */
    public static function rest(string $value, string $label): string
    {
        $tail = mb_substr(trim($value), mb_strlen($label));

        return mb_substr(trim(preg_replace('/^[\s\-–—:,\/+.|]+/u', '', $tail)), 0, 255);
    }
}
