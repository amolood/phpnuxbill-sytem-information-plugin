<?php
register_menu("System Info", true, "system_info", 'SETTINGS', '');

function system_info()
{
    global $ui;
    _admin();
    $ui->assign('_title', 'System Information');
    $ui->assign('_system_menu', 'settings');
    $admin = Admin::_info();
    $ui->assign('_admin', $admin);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reload']) && $_POST['reload'] === 'true') {
        $output = [];
        $retcode = 0;
        $os = strtoupper(PHP_OS);

        if (strpos($os, 'WIN') === 0) {
            // Windows OS
            exec('net stop freeradius', $output, $retcode);
            exec('net start freeradius', $output, $retcode);
        }elseif(strpos(get_system_distro(),'Ubuntu')){
             exec('sudo restart freeradius.service 2>&1', $output, $retcode);
        } else {
            // Linux OS
            exec('sudo systemctl restart freeradius.service 2>&1', $output, $retcode);
        }

        $ui->assign('output', $output);
        $ui->assign('returnCode', $retcode);
    }

    $systemInfo = get_system_info();
    $diskUsage = get_disk_usage();
    $memoryUsage = get_server_memory_usage();
    $serviceTable = generate_service_table();

    $ui->assign('systemInfo', $systemInfo);
    $ui->assign('disk_usage', $diskUsage);
    $ui->assign('memory_usage', $memoryUsage);
    $ui->assign('serviceTable', $serviceTable);

    // Display the template
    $ui->display('system_info.tpl');
}

function get_server_memory_usage()
{
    $os = strtoupper(PHP_OS);
    if (strpos($os, 'WIN') === 0) {
        return get_windows_memory_usage();
    } else {
        return get_linux_memory_usage();
    }
}

function get_windows_memory_usage()
{
    $output = [];
    exec('wmic OS get TotalVisibleMemorySize, FreePhysicalMemory /Value', $output);

    $total_memory = $free_memory = null;
    foreach ($output as $line) {
        if (strpos($line, 'TotalVisibleMemorySize') !== false) {
            $total_memory = intval(preg_replace('/[^0-9]/', '', $line));
        } elseif (strpos($line, 'FreePhysicalMemory') !== false) {
            $free_memory = intval(preg_replace('/[^0-9]/', '', $line));
        }

        if ($total_memory !== null && $free_memory !== null) {
            break;
        }
    }

    if ($total_memory !== null && $free_memory !== null) {
        $total_memory = round($total_memory / 1024);
        $free_memory = round($free_memory / 1024);
        $used_memory = $total_memory - $free_memory;
        $memory_usage_percentage = round($used_memory / $total_memory * 100);

        return [
            'total' => $total_memory,
            'free' => $free_memory,
            'used' => $used_memory,
            'used_percentage' => round($memory_usage_percentage),
        ];
    }

    return null;
}

function get_linux_memory_usage()
{
    $free = shell_exec('free -m');
    $free = trim($free);
    $free_arr = explode("\n", $free);
    $mem = array_filter(explode(" ", $free_arr[1]));
    $mem = array_merge($mem);

    $total_memory = $mem[1];
    $used_memory = $mem[2];
    $free_memory = $total_memory - $used_memory;
    $memory_usage_percentage = round($used_memory / $total_memory * 100);

    return [
        'total' => $total_memory,
        'free' => $free_memory,
        'used' => $used_memory,
        'used_percentage' => round($memory_usage_percentage),
    ];
}

function get_system_info()
{
    $memory_usage = get_server_memory_usage();

    // Get the Idiorm ORM instance
    $db = ORM::getDb();
    $serverInfo = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    $databaseName = $db->query('SELECT DATABASE()')->fetchColumn();
    $serverName = gethostname() ?: $_SERVER['SERVER_NAME'];
    $shellExecEnabled = function_exists('shell_exec');

    // Retrieve the current time from the database
    $currentTime = $db->query('SELECT CURRENT_TIMESTAMP AS current_time_alias')->fetchColumn();

    return [
        'Server Name' => $serverName,
        'Operating System' => php_uname('s'),
        'System Distro' => get_system_distro(),
        'PHP Version' => phpversion(),
        'Server Software' => $_SERVER['SERVER_SOFTWARE'],
        'Server IP Address' => $_SERVER['SERVER_ADDR'],
        'Server Port' => $_SERVER['SERVER_PORT'],
        'Remote IP Address' => $_SERVER['REMOTE_ADDR'],
        'Remote Port' => $_SERVER['REMOTE_PORT'],
        'Database Server' => $serverInfo,
        'Database Name' => $databaseName,
        'System Time' => date("F j, Y g:i a"),
        'Database Time' => date("F j, Y g:i a", strtotime($currentTime)),
        'Shell Exec Enabled' => $shellExecEnabled ? 'Yes' : 'No',
    ];
}

function get_disk_usage()
{
    $os = strtoupper(PHP_OS);
    if (strpos($os, 'WIN') === 0) {
        return get_windows_disk_usage();
    } else {
        return get_linux_disk_usage();
    }
}

function get_windows_disk_usage()
{
    $output = [];
    exec('wmic logicaldisk where "DeviceID=\'C:\'" get Size,FreeSpace /format:list', $output);

    if (!empty($output)) {
        $total_disk = $free_disk = 0;

        foreach ($output as $line) {
            if (strpos($line, 'Size=') === 0) {
                $total_disk = intval(substr($line, 5));
            } elseif (strpos($line, 'FreeSpace=') === 0) {
                $free_disk = intval(substr($line, 10));
            }
        }

        $used_disk = $total_disk - $free_disk;
        $disk_usage_percentage = round(($used_disk / $total_disk) * 100, 2);

        return [
            'total' => format_bytes($total_disk),
            'used' => format_bytes($used_disk),
            'free' => format_bytes($free_disk),
            'used_percentage' => $disk_usage_percentage . '%',
        ];
    }

    return null;
}

function get_linux_disk_usage()
{
    $disk = shell_exec('df / --output=size,used,avail,pcent --block-size=1');
    $disk = trim($disk);
    $disk_arr = explode("\n", $disk);
    $disk = array_filter(explode(" ", preg_replace('/\s+/', ' ', $disk_arr[1])));
    $disk = array_merge($disk);

    return [
        'total' => format_bytes($disk[0]),
        'used' => format_bytes($disk[1]),
        'free' => format_bytes($disk[2]),
        'used_percentage' => $disk[3],
    ];
}

function format_bytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function get_system_distro()
{
    $os = strtoupper(PHP_OS);
    if (strpos($os, 'LIN') === 0) {
        $distro = shell_exec('lsb_release -d');
        return $distro ? trim(substr($distro, strpos($distro, ':') + 1)) : '';
    } elseif (strpos($os, 'WIN') === 0) {
        return get_windows_version();
    }

    return '';
}

function get_windows_version()
{
    $version = shell_exec('ver') ?: php_uname('v') ?: ($_SERVER['SERVER_SOFTWARE'] ?? $_SERVER['WINDIR'] ?? '');
    return trim($version);
}

function generate_service_table()
{
    $services_to_check = ["FreeRADIUS", "MySQL", "MariaDB", "Cron", "SSHd"];
    $table = ['title' => 'Service Status', 'rows' => []];

    foreach ($services_to_check as $service_name) {
        $running = check_service_status(strtolower($service_name));
        $class = $running ? "label pull-right bg-green" : "label pull-right bg-red";
        $label = $running ? "running" : "not running";
        $table['rows'][] = [$service_name, sprintf('<small class="%s">%s</small>', $class, $label)];
    }

    return $table;
}

function check_service_status($service_name)
{
    if (empty($service_name)) {
        return false;
    }

    $os = strtoupper(PHP_OS);
    if (strpos($os, 'WIN') === 0) {
        $command = sprintf('sc query "%s" | findstr RUNNING', $service_name);
        exec($command, $output, $result_code);
        return $result_code === 0 || !empty($output);
    } else {
        $command = sprintf("pgrep %s", escapeshellarg($service_name));
        exec($command, $output, $result_code);
        return $result_code === 0;
    }
}
?>
