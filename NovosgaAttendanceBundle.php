<?php

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\AttendanceBundle;

use Novosga\Module\BaseModule;

class NovosgaAttendanceBundle extends BaseModule
{
    public function getIconName()
    {
        return 'pencil-square-o';
    }

    public function getDisplayName()
    {
        return 'Atendimento';
    }

    public function getHomeRoute()
    {
        return 'novosga_attendance_index';
    }
}
