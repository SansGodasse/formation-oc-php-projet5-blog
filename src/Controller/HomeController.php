<?php

namespace Controller;

use Model\Manager\CategoryManager;
use Model\Manager\PostManager;
use Twig_Environment;

class HomeController extends Controller
{
    /**
     * @var PostManager
     */
    protected $postManager;
    /**
     * @var CategoryManager
     */
    protected $categoryManager;
    /**
     * @var Twig_Environment
     */
    protected $twig;

    const VIEW_HOME = 'home/home.twig';

    public function __construct(
                                PostManager $postManager,
                                CategoryManager $categoryManager,
                                Twig_Environment $twig
    )
    {
        parent::__construct($twig);
        $this->postManager = $postManager;
        $this->categoryManager = $categoryManager;
    }

    /**
     * Show the home page
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function showHome()
    {
        $posts = $this->postManager->getAll();
        self::render(self::VIEW_HOME, [
            'posts' => $posts
        ]);
    }
}