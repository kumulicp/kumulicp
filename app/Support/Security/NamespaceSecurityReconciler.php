<?php

namespace App\Support\Security;

/**
 * Works out the minimal label/annotation changes needed to bring a
 * namespace in line with its desired security policy. Pure: callers fetch
 * the current metadata and apply the result, so both server drivers share
 * one definition of "what should change".
 *
 * The kumulicp.io/security-tier annotation marks a namespace whose Pod
 * Security labels KumuliCP owns. It's what lets a plan moving back to the
 * `none` tier remove the labels again, without ever touching labels an
 * admin set on a namespace KumuliCP never managed.
 */
class NamespaceSecurityReconciler
{
    public const ANNOTATION = 'kumulicp.io/security-tier';

    /**
     * @param  array{tier: string, labels: array<string, string>}|null  $desired  null = KumuliCP doesn't manage this namespace
     * @param  array<string, string>  $labels  the namespace's current labels
     * @param  array<string, string>  $annotations  the namespace's current annotations
     * @return array{labels: array<string, string|null>, annotations: array<string, string|null>} merge-patch values (null removes the key); both empty when nothing needs to change
     */
    public static function changes(?array $desired, array $labels, array $annotations): array
    {
        $none = ['labels' => [], 'annotations' => []];

        if ($desired === null) {
            return $none;
        }

        $current = array_filter(
            $labels,
            fn ($value, $key) => str_starts_with($key, SecurityTier::LABEL_PREFIX),
            ARRAY_FILTER_USE_BOTH,
        );
        $managed = array_key_exists(self::ANNOTATION, $annotations);
        $wanted = $desired['labels'];

        // Nothing wanted: only clean up after ourselves
        if ($wanted === []) {
            if (! $managed) {
                return $none;
            }

            return [
                'labels' => array_fill_keys(array_keys($current), null),
                'annotations' => [self::ANNOTATION => null],
            ];
        }

        $label_changes = [];
        foreach ($wanted as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $label_changes[$key] = $value;
            }
        }
        foreach (array_keys($current) as $key) {
            if (! array_key_exists($key, $wanted)) {
                $label_changes[$key] = null;
            }
        }

        $annotation_changes = ($annotations[self::ANNOTATION] ?? null) !== $desired['tier']
            ? [self::ANNOTATION => $desired['tier']]
            : [];

        return ['labels' => $label_changes, 'annotations' => $annotation_changes];
    }

    public static function isEmpty(array $changes): bool
    {
        return $changes['labels'] === [] && $changes['annotations'] === [];
    }

    /**
     * Labels/annotations to include when the namespace is first created.
     *
     * @param  array{tier: string, labels: array<string, string>}|null  $desired
     * @return array{labels: array<string, string>, annotations: array<string, string>}
     */
    public static function metadataForCreate(?array $desired): array
    {
        if ($desired === null || $desired['labels'] === []) {
            return ['labels' => [], 'annotations' => []];
        }

        return [
            'labels' => $desired['labels'],
            'annotations' => [self::ANNOTATION => $desired['tier']],
        ];
    }
}
