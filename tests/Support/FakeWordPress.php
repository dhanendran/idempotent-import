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
        $this->userMeta[(int) $userId][$key][] = $value;
    }

    public function deleteUserMeta($userId, $key)
    {
        unset($this->userMeta[(int) $userId][$key]);
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
        $termId = $this->nextTermId++;
        $ttId   = $this->nextTtId++;
        $this->terms[$termId] = [
            'name'             => $name,
            'slug'             => $slug,
            'taxonomy'         => $taxonomy,
            'parent'           => (int) ($args['parent'] ?? 0),
            'description'      => $args['description'] ?? '',
            'term_taxonomy_id' => $ttId,
        ];
        return ['term_id' => $termId, 'term_taxonomy_id' => $ttId];
    }

    public function addTermMeta($termId, $key, $value)
    {
        $this->termMeta[(int) $termId][$key][] = $value;
    }

    public function deleteTermMeta($termId, $key)
    {
        unset($this->termMeta[(int) $termId][$key]);
    }

    public function updateTermParent($termId, $taxonomy, $parentTermId)
    {
        if (isset($this->terms[(int) $termId])) {
            $this->terms[(int) $termId]['parent'] = (int) $parentTermId;
        }
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

    public function insertPost(array $data)
    {
        $id = $this->nextPost++;
        $this->posts[$id] = $this->unslash($data);
        return $id;
    }

    public function updatePostFields($postId, array $fields)
    {
        $postId = (int) $postId;
        unset($fields['ID']);
        $this->posts[$postId] = array_merge($this->posts[$postId] ?? [], $this->unslash($fields));
    }

    public function addPostMeta($postId, $key, $value)
    {
        $this->postMeta[(int) $postId][$key][] = $value;
    }

    public function deletePostMeta($postId, $key)
    {
        unset($this->postMeta[(int) $postId][$key]);
    }

    public function updatePostMeta($postId, $metaKey, $value)
    {
        $this->postMeta[(int) $postId][$metaKey] = [$value];
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
        $this->commentMeta[(int) $commentId][$key][] = $value;
    }

    public function deleteCommentMeta($commentId, $key)
    {
        unset($this->commentMeta[(int) $commentId][$key]);
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
