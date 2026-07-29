<?php

declare(strict_types=1);

namespace IdempotentImport\Tests\Support;

use IdempotentImport\Contracts\Ledger;
use IdempotentImport\Contracts\WordPress;

/**
 * In-memory implementation of the WordPress gateway. Records every mutation so
 * tests can assert on the resulting state without a live WordPress.
 */
class FakeWordPress implements WordPress
{
    public array $users = [];       // id => columns
    public array $userMeta = [];    // id => [key => [values]]
    public array $terms = [];       // term_id => [name,slug,taxonomy,parent,term_taxonomy_id]
    public array $termMeta = [];    // term_id => [key => [values]]
    public array $posts = [];       // id => columns
    public array $postMeta = [];    // id => [key => [values]]
    public array $postTerms = [];   // id => [taxonomy => [termIds]]
    public array $comments = [];    // id => columns
    public array $commentMeta = []; // id => [key => [values]]
    public array $options = [];     // name => [value, autoload]
    public array $attachmentUrls = []; // id => url

    /** Meta WordPress itself would seed on insert, e.g. _pingme. key => [values] */
    public array $onInsertPostMeta = [];

    /** Every updatePostFields() call, as ['id' => int, 'fields' => array]. */
    public array $updatedPostFields = [];

    /** Every updateTermFields() call, as ['id' => int, 'fields' => array]. */
    public array $updatedTermFields = [];

    /** Every setTermsAutoIncrement() call, as ['terms' => int, 'term_taxonomy' => int]. */
    public array $termsAutoIncrement = [];

    private int $nextUser = 100;
    private int $nextTermId = 200;
    private int $nextTtId = 500;
    private int $nextPost = 1000;
    private int $nextComment = 3000;

    /* ---- Destination reconciliation ---- */

    public function missingDestIds($type, Ledger $ledger)
    {
        $recorded = array_map('intval', array_values($ledger->all($type)));
        if ('term' === $type) {
            $present = array_map(static fn ($t) => (int) $t['term_taxonomy_id'], $this->terms);
        } else {
            $stores = ['user' => $this->users, 'post' => $this->posts, 'comment' => $this->comments];
            if (!isset($stores[$type])) {
                return [];
            }
            $present = array_map('intval', array_keys($stores[$type]));
        }
        return array_values(array_diff($recorded, $present));
    }

    public function nonMemberUserIds(Ledger $ledger)
    {
        $gone = [];
        foreach ($ledger->all('user') as $destId) {
            if (empty($this->userMeta[(int) $destId]['wp_capabilities'])) {
                $gone[] = (int) $destId;
            }
        }
        return $gone;
    }

    /* ---- Users ---- */

    public function getUserIdBy($field, $value)
    {
        $col = 'email' === $field ? 'user_email' : ('slug' === $field ? 'user_nicename' : 'user_login');
        foreach ($this->users as $id => $u) {
            if (isset($u[$col]) && (string) $u[$col] === (string) $value) {
                return (int) $id;
            }
        }
        return null;
    }

    public function insertUser(array $data)
    {
        $id = $this->nextUser++;
        unset($data['user_pass']);
        $this->users[$id] = $data;
        return $id;
    }

    public function addUserMeta($userId, $key, $value)
    {
        $this->userMeta[(int) $userId][$this->unslash($key)][] = $value;
    }

    public function deleteUserMeta($userId, $key)
    {
        unset($this->userMeta[(int) $userId][$this->unslash($key)]);
    }

    /* ---- Terms ---- */

    public function getTermBy($taxonomy, $field, $value)
    {
        $col = 'name' === $field ? 'name' : 'slug';
        foreach ($this->terms as $tid => $t) {
            if ($t['taxonomy'] === $taxonomy && (string) $t[$col] === (string) $value) {
                return ['term_id' => (int) $tid, 'term_taxonomy_id' => (int) $t['term_taxonomy_id']];
            }
        }
        return null;
    }

    public function insertTerm($name, $taxonomy, array $args)
    {
        $slug = $args['slug'] ?? $this->slugify($name);
        foreach ($this->terms as $tid => $t) {
            if ($t['taxonomy'] === $taxonomy && $t['slug'] === $slug) {
                return ['term_id' => (int) $tid, 'term_taxonomy_id' => (int) $t['term_taxonomy_id']];
            }
        }
        return $this->storeTerm($this->nextTermId++, $this->nextTtId++, $name, $taxonomy, $args);
    }

    public function insertTermWithIds($termId, $ttId, $name, $taxonomy, array $args)
    {
        return $this->storeTerm((int) $termId, (int) $ttId, $name, $taxonomy, $args);
    }

    /** Taxonomies the destination registers. Empty means "every taxonomy exists". */
    public array $registeredTaxonomies = [];

    public function taxonomyExists($taxonomy)
    {
        return [] === $this->registeredTaxonomies
            || in_array((string) $taxonomy, $this->registeredTaxonomies, true);
    }

    public function getTermRow($termId)
    {
        $t = $this->terms[(int) $termId] ?? null;
        if (!$t) {
            return null;
        }
        return [
            'term_id'    => (int) $termId,
            'name'       => (string) $t['name'],
            'slug'       => (string) $t['slug'],
            'term_group' => (int) ($t['term_group'] ?? 0),
        ];
    }

    public function getTermTaxonomyRow($ttId)
    {
        foreach ($this->terms as $tid => $t) {
            if ((int) $t['term_taxonomy_id'] === (int) $ttId) {
                return [
                    'term_taxonomy_id' => (int) $ttId,
                    'term_id'          => (int) $tid,
                    'taxonomy'         => (string) $t['taxonomy'],
                    'parent'           => (int) $t['parent'],
                ];
            }
        }
        return null;
    }

    /**
     * The store behind both insert paths. Keyed by term_id, so a term_id shared
     * across taxonomies cannot be represented — which is exactly why the importer
     * must not split one (see Terms::recordMaps).
     */
    private function storeTerm(int $termId, int $ttId, string $name, string $taxonomy, array $args): array
    {
        $this->terms[$termId] = [
            'name'             => $this->unslash($name),
            'slug'             => $this->unslash($args['slug'] ?? $this->slugify($name)),
            'taxonomy'         => $taxonomy,
            'parent'           => (int) ($args['parent'] ?? 0),
            'description'      => $this->unslash($args['description'] ?? ''),
            'term_group'       => (int) ($args['term_group'] ?? 0),
            'count'            => (int) ($args['count'] ?? 0),
            'term_taxonomy_id' => $ttId,
        ];
        $this->nextTermId = max($this->nextTermId, $termId + 1);
        $this->nextTtId   = max($this->nextTtId, $ttId + 1);
        return ['term_id' => $termId, 'term_taxonomy_id' => $ttId];
    }

    public function addTermMeta($termId, $key, $value)
    {
        $this->termMeta[(int) $termId][$this->unslash($key)][] = $value;
    }

    public function deleteTermMeta($termId, $key)
    {
        unset($this->termMeta[(int) $termId][$this->unslash($key)]);
    }

    public function updateTermFields($termId, $taxonomy, array $fields)
    {
        $termId = (int) $termId;
        if (!isset($this->terms[$termId])) {
            return;
        }
        $this->updatedTermFields[] = ['id' => $termId, 'fields' => $fields];
        foreach (['name', 'slug', 'description'] as $column) {
            if (array_key_exists($column, $fields)) {
                $this->terms[$termId][$column] = $this->unslash($fields[$column]);
            }
        }
        foreach (['parent', 'term_group'] as $column) {
            if (array_key_exists($column, $fields)) {
                $this->terms[$termId][$column] = (int) $fields[$column];
            }
        }
    }

    public function setTermsAutoIncrement($nextTermId, $nextTtId)
    {
        $this->termsAutoIncrement[] = ['terms' => (int) $nextTermId, 'term_taxonomy' => (int) $nextTtId];
        $highestTerm = $this->terms ? max(array_map('intval', array_keys($this->terms))) : 0;
        $highestTt   = $this->terms ? max(array_map(static fn ($t) => (int) $t['term_taxonomy_id'], $this->terms)) : 0;
        $this->nextTermId = max((int) $nextTermId, $highestTerm + 1, $this->nextTermId);
        $this->nextTtId   = max((int) $nextTtId, $highestTt + 1, $this->nextTtId);
    }

    /* ---- Posts ---- */

    public function getPostIdBy($field, $value, $postType)
    {
        foreach ($this->posts as $id => $p) {
            if ('guid' === $field) {
                if (($p['guid'] ?? null) === $value) {
                    return (int) $id;
                }
            } elseif (($p['post_name'] ?? null) === $value && ($p['post_type'] ?? null) === $postType) {
                return (int) $id;
            }
        }
        return null;
    }

    public function getPost($postId)
    {
        return $this->posts[(int) $postId] ?? null;
    }

    /**
     * Mirrors wp_insert_post(): an `import_id` claims that ID, but is ignored
     * without error when the ID is already taken.
     */
    public function insertPost(array $data)
    {
        $importId = (int) ($data['import_id'] ?? 0);
        unset($data['import_id']);

        $id = ($importId > 0 && !isset($this->posts[$importId])) ? $importId : $this->nextPost++;
        $this->posts[$id] = $this->unslash($data);
        foreach ($this->onInsertPostMeta as $key => $values) {
            $this->postMeta[$id][$key] = $values;
        }
        return $id;
    }

    public function setPostsAutoIncrement($nextId)
    {
        $highest = $this->posts ? max(array_map('intval', array_keys($this->posts))) : 0;
        $this->nextPost = max((int) $nextId, $highest + 1, $this->nextPost);
    }

    public function updatePostFields($postId, array $fields)
    {
        $postId = (int) $postId;
        unset($fields['ID']);
        $fields = $this->unslash($fields);
        $this->updatedPostFields[] = ['id' => $postId, 'fields' => $fields];
        $this->posts[$postId] = array_merge($this->posts[$postId] ?? [], $fields);
    }

    public function addPostMeta($postId, $key, $value)
    {
        $this->postMeta[(int) $postId][$this->unslash($key)][] = $value;
    }

    public function deletePostMeta($postId, $key)
    {
        unset($this->postMeta[(int) $postId][$this->unslash($key)]);
    }

    public function updatePostMeta($postId, $metaKey, $value)
    {
        $this->postMeta[(int) $postId][$this->unslash($metaKey)] = [$value];
    }

    public function postMetaKeys($postId)
    {
        return array_map('strval', array_keys($this->postMeta[(int) $postId] ?? []));
    }

    public function setPostTerms($postId, $taxonomy, array $termIds, $append = false)
    {
        $this->postTerms[(int) $postId][$taxonomy] = array_map('intval', $termIds);
    }

    /* ---- Comments ---- */

    public function findCommentId(array $criteria)
    {
        foreach ($this->comments as $id => $c) {
            $ok = true;
            foreach ($criteria as $k => $v) {
                if ((string) ($c[$k] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return (int) $id;
            }
        }
        return null;
    }

    public function insertComment(array $data)
    {
        $id = $this->nextComment++;
        $this->comments[$id] = $data;
        return $id;
    }

    public function addCommentMeta($commentId, $key, $value)
    {
        $this->commentMeta[(int) $commentId][$this->unslash($key)][] = $value;
    }

    public function deleteCommentMeta($commentId, $key)
    {
        unset($this->commentMeta[(int) $commentId][$this->unslash($key)]);
    }

    public function updateCommentFields($commentId, array $fields)
    {
        $commentId = (int) $commentId;
        unset($fields['comment_ID']);
        $this->comments[$commentId] = array_merge($this->comments[$commentId] ?? [], $fields);
    }

    /* ---- Options ---- */

    public function getOption($name, $default = false)
    {
        return $this->options[$name]['value'] ?? $default;
    }

    public function updateOption($name, $value, $autoload = 'yes')
    {
        $this->options[$name] = ['value' => $value, 'autoload' => $autoload];
    }

    /* ---- Media ---- */

    public function sideloadMedia($url, $parentPostId)
    {
        $id = $this->nextPost++;
        $this->posts[$id] = [
            'post_type'   => 'attachment',
            'post_parent' => (int) $parentPostId,
            'guid'        => 'https://dest.test/wp-content/uploads/' . basename((string) parse_url($url, PHP_URL_PATH)),
        ];
        $this->attachmentUrls[$id] = $this->posts[$id]['guid'];
        $this->postMeta[$id]['_wp_attached_file'] = [basename((string) parse_url($url, PHP_URL_PATH))];
        return $id;
    }

    public function getAttachmentUrl($attachmentId)
    {
        return $this->attachmentUrls[(int) $attachmentId] ?? null;
    }

    public function findAttachmentByFilename($filename)
    {
        foreach ($this->postMeta as $id => $meta) {
            if (isset($meta['_wp_attached_file'][0]) && basename((string) $meta['_wp_attached_file'][0]) === basename($filename)) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Mimic WordPress unslashing stored input (wp_insert_post/wp_update_post
     * unslash before writing), so assertions see the logical, unslashed value.
     *
     * @param mixed $v
     * @return mixed
     */
    private function unslash($v)
    {
        if (is_string($v)) {
            return stripslashes($v);
        }
        if (is_array($v)) {
            return array_map([$this, 'unslash'], $v);
        }
        return $v;
    }

    private function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-');
    }
}
