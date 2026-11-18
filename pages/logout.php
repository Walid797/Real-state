<?php
declare(strict_types=1);

App\Auth::logout();
redirect('/login');
