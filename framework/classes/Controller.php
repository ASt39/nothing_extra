<?php
/**
 * Created by PhpStorm.
 * User: Alexandr Statut
 * Date: 22.07.2019
 * Time: 14:01
 */

namespace framework\classes;


class Controller {

    public $benchmark;
    public $buffer;
    public $log;
    public $url;

    protected $component = false;

    protected $auth      = false;

    public function __construct()
    {
        $this->benchmark = Benchmark::getInstance();
        $this->benchmark->addMark('_controller_start_');

        $this->checkSession();

        $this->buffer    = Buffer::getInstance();
        $this->log       = Log::getInstance();
        $this->url       = new Route();

        SystemMessage::recoveryMessages();
        
        if ($this->auth !== false && !$this->isUserAuth()) {
            Route::redirect(SITE . config('options.login_page'));
        }

        $this->csrfGen();
    }

    protected function goTo404()
    {
        header("HTTP/1.1 404 Not Found");
        include_once ROOT . '404.php';
        exit;
    }

    protected function csrfGen($replace = false)
    {
        if ($replace || !Session::get('csrf')) {
            Session::set(Crypt::generatePassword(32, true, true, true, true), 'csrf');
        }
    }

    protected function csrfGet()
    {
        return htmlspecialchars(Session::get('csrf'));
    }

    protected function csrfHtml()
    {
        printf('<input type="hidden" name="csrf" value="%s" />', htmlspecialchars(Session::get('csrf')));
    }

    protected function csrfCheck()
    {
        $csrf_session = Session::get('csrf') ?? NULL;
        $csrf_post    = Request::post('csrf') ?? NULL;

        if ($csrf_session !== $csrf_post) {
            $this->goTo404();
        }
    }

    protected function isUserAuth()
    {
        return (bool)Session::get(config('options.user_auth_mark'));
    }

    protected function isAdminAuth()
    {
        return (bool)Session::get(config('options.admin_auth_mark'));
    }

    protected function isRootAuth()
    {
        return (bool)Session::get(config('options.root_auth_mark'));
    }

    protected function authUser()
    {
        Session::set(true, config('options.user_auth_mark'));
    }

    protected function authAdmin()
    {
        Session::set(true, config('options.admin_auth_mark'));
    }

    protected function authRoot()
    {
        Session::set(true, config('options.root_auth_mark'));
    }

    /**
     * Загрузить шаблон
     *
     * @param string|bool $name
     */
    protected function loadTemplate($name = false)
    {
        $this->benchmark->addMark('_template_load_start_');

        $name = $name == false ? 'main_template' : $name;

        $general_folder = defined('ADMIN') ? 'admin' : 'app';

        if (file_exists(ROOT . $general_folder . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $name . EXT)) {
            include_once ROOT . $general_folder . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $name . EXT;
        }
    }

    protected function loadComponent($name, array $vars = [])
    {
        $name_parts = explode('/', $name);
        foreach ($name_parts as $index => $part) {
            $name_parts[$index] = ($index + 1) == count($name_parts)
                ? ucfirst($part)
                : lcfirst($part);
        }
        $class_name = implode('\\', $name_parts);

        $general_folder = defined('ADMIN') ? 'admin' : 'app';

        $namespace_class = defined('ADMIN')
            ? "\\admin\\controllers\\components\\" . $class_name
            : "\\app\\controllers\\components\\" . $class_name;

        if (class_exists($namespace_class)) {

            $method = config('options.def_method');
            $page = new $namespace_class();

            empty($vars)
                ? $page->$method()
                : call_user_func_array([$page, $method], $vars);

        } else if (file_exists(ROOT . $general_folder . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . $name . EXT)) {

            extract($vars);
            array_walk($vars, function ($value, $key) {
                $this->buffer->$key = $value;
            });

            include ROOT . $general_folder . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . $name . EXT;
        }
    }

    protected function pageTitle()
    {
        $method = defined('PAGE_METHOD') ? PAGE_METHOD : config('options.def_method');

        return
            $this->langLine(PAGE . '_' . $method . '_title')
                ? $this->langLine(PAGE . '_' . $method . '_title')
                : ($this->langLine(PAGE  . '_title')
                    ? $this->langLine(PAGE  . '_title')
                    : $this->langLine('main_title')
            );
    }

    protected function pageDescription()
    {
        $method = defined('PAGE_METHOD') ? PAGE_METHOD : config('def_method');

        return
            $this->langLine(PAGE . '_' . $method . '_description')
                ? $this->langLine(PAGE . '_' . $method . '_description')
                : ($this->langLine(PAGE  . '_description')
                    ? $this->langLine(PAGE  . '_description')
                    : $this->langLine('main_description')
            );
    }

    /**
     * Получить языковую переменную
     *
     * @param $name
     * @param bool $file
     * @return string
     */
    protected function langLine($name, $file = false)
    {
        # Для начала получим резервный вариант для дефолтного языка
        # Путь до файла с текстом для дефолтного языка
        $namespace_lang_def = defined('ADMIN')
            ? "\\admin\\langs\\" . strtolower(config('options.def_lang')) . '\\' . ucfirst($file ?: 'main')
            : "\\app\\langs\\" . strtolower(config('options.def_lang')) . '\\' . ucfirst($file ?: 'main');

        $lang_class_def = $namespace_lang_def::getInstance();
        $result_def     = property_exists($lang_class_def, $name)
            ? $lang_class_def->$name
            : null;

        # Теперь попробуем получить вариант для текущего языка
        # Путь до файла с текстом исходя из выбранного языка
        $namespace_lang = defined('ADMIN')
            ? "\\admin\\langs\\" . strtolower(LANG) . '\\' . ucfirst($file ?: 'main')
            : "\\app\\langs\\" . strtolower(LANG) . '\\' . ucfirst($file ?: 'main');

        $lang_class = $namespace_lang::getInstance();
        $result     = property_exists($lang_class, $name)
            ? $lang_class->$name
            : null;

        return $result ?: ($result_def ?: '');
    }

    /**
     * Проверка сессии
     */
    protected function checkSession()
    {
        if (session_id() && !isAjax() && PAGE != 'login' && !$this->component) {

            $_SESSION[config('options.session_array')]['lastPage']     = Session::get('currentPage') ?? null;
            $_SESSION[config('options.session_array')]['currentPage']  = Route::fullUrl();

            Session::set(time(), 'lastActivity');
        }
    }

}
