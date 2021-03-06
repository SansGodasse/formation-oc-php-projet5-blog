<?php

namespace Helper;

use Controller\BlogController;
use Controller\Controller;
use Exception;
use Michelf\Markdown;
use Model\Entity\Category;
use Model\Entity\Comment;
use Model\Entity\Entity;
use Model\Entity\Post;
use Model\Entity\Tag;
use Model\Manager\PostManager;

class BlogHelper
{
    const KEY_POST_TITLE = "post-title";
    const KEY_POST_EXCERPT = "post-excerpt";
    const KEY_POST_CONTENT = "post-content";
    const KEY_CATEGORY_NAME = "category-name";


    private function __construct()
    {
        // Disabled
    }
    
    /**
     * Extract names from an array of Tag
     *
     * @param array $tags
     * @return mixed
     */
    public static function getTagNames(array $tags)
    {
        $tagNames = [];
        foreach ($tags as $tag) {
            $tagNames[] = $tag->getName();
        }
        return $tagNames;
    }

    /**
     * Check if an Entity has to be deleted
     *
     * @param Entity $oldEntity
     * @param array $entityIdsToDelete
     * @return bool
     */
    public static function isEntityToDelete(Entity $oldEntity, array $entityIdsToDelete): bool
    {
        foreach ($entityIdsToDelete as $entityIdToDelete) {
            if ($entityIdToDelete === $oldEntity->getId()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return Tag entities
     *
     * @return array
     */
    public static function getTagsFromForm(): ?array
    {
        $tags = null;

        if (isset($_POST[BlogController::KEY_TAGS])) {
            foreach ($_POST[BlogController::KEY_TAGS] as $tag) {
                $tags[] = new Tag(['name' => $tag]);
            }
        }

        return $tags;
    }

    /**
     * Return Category entities
     *
     * @return array|null
     */
    public static function getCategoriesFromForm(): ?array
    {
        $categories = null;

        if (isset($_POST[BlogController::KEY_CATEGORIES])) {
            foreach ($_POST[BlogController::KEY_CATEGORIES] as $category) {
                $categories[] = new Category(['name' => $category]);
            }
        }

        return $categories;
    }

    /**
     * Create a Post from a form (thanks to $_POST)
     * Work for addPost and editPost
     *
     * @return Post|null
     */
    public static function buildPostFromForm(): ?Post
    {
        $post = new Post();

        if (
            isset($_POST[self::KEY_POST_TITLE]) && !empty($_POST[self::KEY_POST_TITLE]) &&
            isset($_POST[self::KEY_POST_EXCERPT]) && !empty($_POST[self::KEY_POST_EXCERPT]) &&
            isset($_POST[self::KEY_POST_CONTENT]) && !empty($_POST[self::KEY_POST_CONTENT]) &&
            isset($_POST['post-author-id'])
        ) {
            // Common
            $post->setTitle($_POST[self::KEY_POST_TITLE]);
            $post->setExcerpt($_POST[self::KEY_POST_EXCERPT]);
            $post->setContent($_POST[self::KEY_POST_CONTENT]);
            $post->setAuthorId($_POST['post-author-id']);

            // Add a new post
            if (isset($_POST['add-post'])) {
                $post->setCreationDate(date(Controller::MYSQL_DATE_FORMAT));
            }

            // Edit a post
            if (isset($_POST['edit-post'])) {
                $post->setId($_POST['edit-post']);
                $post->setLastModificationDate(date(Controller::MYSQL_DATE_FORMAT));
            }
            if (isset($_POST['post-editor-id'])) {
                $post->setLastEditorId($_POST['post-editor-id']);
            }
            if (isset($_POST['select-author-id'])) {
                $post->setAuthorId($_POST['select-author-id']);
            }

            self::setPostSettingsFromForm($post);

            return $post;

        } else {
            return null;
        }
    }

    /**
     * Create a Category from a form with $_POST
     *
     * @return Category|null
     */
    public static function buildCategoryFromForm(): ?Category
    {
        $category = new Category();

        if (isset($_POST[self::KEY_CATEGORY_NAME]) && !empty($_POST[self::KEY_CATEGORY_NAME])) {
            $category->setName(htmlspecialchars($_POST[self::KEY_CATEGORY_NAME]));

            // Category to edit
            if (isset($_POST['edit-category'])) {
                $category->setId((int) $_POST['edit-category']);
            }

            return $category;

        } else {
            return null;
        }
    }

    /**
     * Create a Comment from $_POST
     *
     * @return Comment|null
     */
    public static function buildCommentFromForm(): ?Comment
    {
        $comment = new Comment();

        if (isset($_POST['comment-id'])) {
            $comment->setId((int) $_POST['comment-id']);
        }

        if (isset($_POST['editor-id'])) {
            $comment->setLastEditorId((int) $_POST['editor-id']);
        }

        if (isset($_POST['creation-date'])) {
            $comment->setCreationDate($_POST['creation-date']);
        }

        if (isset($_POST['comment-approved'])) {
            $comment->setApproved(true);
        }

        if (isset($_POST['author-id'])) {
            $comment->setAuthorId((int) $_POST['author-id']);
        }

        if (isset($_POST['post-id'])) {
            $comment->setPostId((int) $_POST['post-id']);
        }

        if (isset($_POST['comment'])) {
            $comment->setContent($_POST['comment']);
        }

        if (isset($_POST['parent-id'])) {
            $comment->setParentId((int) $_POST['parent-id']);
        }

        return $comment;
    }

    /**
     * Cut the title and the excerpt of a post if they are too big. Return a message explaining the modifications.
     *
     * @param Post $post
     * @param string $message
     * @return string
     */
    public static function cutPost(Post $post, string $message = '')
    {
        $endOfSentence = " caractères. Il a été coupé.<br>";
        // Title
        if (strlen($post->getTitle()) > PostManager::TITLE_LENGTH) {
            // We cut
            $post->setTitle(substr($post->getTitle(), 0, PostManager::TITLE_LENGTH));
            $message .= "Attention : le titre ne doit pas dépasser " . PostManager::TITLE_LENGTH . $endOfSentence;
        }
        // Excerpt
        if (strlen($post->getExcerpt()) > PostManager::EXCERPT_LENGTH) {
            // We cut
            $post->setExcerpt(substr($post->getExcerpt(), 0, PostManager::EXCERPT_LENGTH));
            $message .= "Attention : l'extrait ne doit pas dépasser " . PostManager::EXCERPT_LENGTH . $endOfSentence;
        }
        // Content
        if (strlen($post->getContent()) > PostManager::CONTENT_LENGTH) {
            // We cut
            $post->setContent(substr($post->getContent(), 0, PostManager::CONTENT_LENGTH));
            $message .= "Attention : le contenu ne doit pas dépasser " . PostManager::CONTENT_LENGTH . $endOfSentence;
        }

        return $message;
    }

    /**
     * Convert markdown content
     *
     * @param string $content
     * @return string
     */
    public static function convertMarkdown(string $content)
    {
        return Markdown::defaultTransform($content);
    }

    /**
     * Prepare a post before showing it (convert dates and markdown contents)
     *
     * @param Post $post
     * @throws Exception
     */
    public static function prepareAPost(Post $post)
    {
        self::convertDatesOfPost($post);
        if ($post->isMarkdown() && !empty($post->getContent())) {
            $post->setContent(BlogHelper::convertMarkdown($post->getContent()));
        }
    }

    /**
     * Change the date format use in a post
     *
     * @param Post $post
     * @throws Exception
     */
    public static function convertDatesOfPost(Post $post)
    {
        $post->setCreationDate(Controller::formatDate($post->getCreationDate()));

        if ($post->getLastModificationDate() !== null) {
            $post->setLastModificationDate(Controller::formatDate($post->getLastModificationDate()));
        }
    }

    /**
     * Change the date format use in a comment
     *
     * @param Comment $comment
     * @throws Exception
     */
    public static function convertDatesOfComment(Comment $comment)
    {
        $comment->setCreationDate(Controller::formatDate($comment->getCreationDate()));

        if ($comment->getLastModificationDate() !== null) {
            $comment->setLastModificationDate(Controller::formatDate($comment->getLastModificationDate()));
        }
    }

    // Private

    /**
     * Set tags, categories and markdown settings to a post
     *
     * @param Post $post
     */
    private static function setPostSettingsFromForm(Post $post)
    {
        // Tags
        $tags = self::getTagsFromForm();
        if ($tags) {
            $post->setTags($tags);
        }

        // Categories
        $categories = self::getCategoriesFromForm();
        if ($categories) {
            $post->setCategories($categories);
        }

        // Markdown
        if (isset($_POST['markdown-content']) && !empty($_POST['markdown-content'])) {
            $post->setMarkdown(true);
        }
    }
}
