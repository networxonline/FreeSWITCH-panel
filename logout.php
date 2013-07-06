<?php

            header('WWW-Authenticate: Basic realm="Conference"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'This section only available for export priv.';
            exit;

