<?php

namespace jstnryan\AccessLog;

use DateTime;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AccessLog {

    const httpMethodID = [
        'GET' => 1,
        'POST' => 2,
        'PUT' => 3,
        'PATCH' => 4,
        'DELETE' => 5,
        'HEAD' => 6,
        'CONNECT' => 7,
        'OPTIONS' => 8,
        'TRACE' => 9
    ];

    /** @var array */
    protected $settings = [
        'tableName' => 'accessLog',     // The name of the accessLog table to write to
        'idColumn' => 'accessLogID',    // The name of the autoincrement primary key for the log table
        'writeOnce' => false,           // False writes to DB for request and response, true waits until after response
        'custom' => [],                 // A list of custom column names to populate (indexed!)
        'captureResponse' => false,     // Whether or not to record the full body of the response to the call
        'ignoredPaths' =>     [         // Array of stings, each of which represent a path or path root which should not
            //'/authorize',             //   be logged; for example, '/authorize' matches '/authorize', '/authorize/',
        ],                              //   and '/authorize/login', but not '/auth'
    ];

    /** @var PDO */
    protected $db;

    /** @var callable[] */
    protected $custom = [];

    public function __construct(PDO $db, $settings, ...$custom) {
        $this->db = $db;
        $this->settings = array_merge($this->settings, $settings);
        if (!is_array($this->settings['ignoredPaths'])) {
            $this->settings['ignoredPaths'] = [$this->settings['ignoredPaths']];
        }
        $this->custom = $custom;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next) {
        /* If request path matches ignore, allow */
        if ($this->isIgnoredPath($request->getUri()->getPath())) {
            $response = $next($request, $response);
        } else {
            if (count($this->custom) !== count($this->settings['custom'])) {
                throw new Exception('AccessLog: The number of custom methods passed (' . count($this->custom) . ') does not match the number of custom column names provided (' . count($this->settings['custom']) . ').');
            }
            $columns = [];
            for ($i = 0; $i < count($this->custom); $i++) {
                $columns[$this->settings['custom'][$i]] = call_user_func($this->custom[$i], $request, $response, null);
            }
            $last = null;
            $qs = isset($_SERVER['QUERY_STRING']) ?
                $this->proper_parse_str(urldecode($_SERVER['QUERY_STRING'])) :
                '';
            if (!$this->settings['writeOnce']) {
                $last = $this->writeBefore(
                    (new DateTime)->setTimestamp($_SERVER['REQUEST_TIME'])->format('Y-m-d H:i:s'),
                    parse_url($_SERVER['REQUEST_URI'])['path'],
                    $this::httpMethodID[$_SERVER['REQUEST_METHOD']],
                    json_encode([
                        'querystring' => $qs,
                        'body' => $request->getParsedBody()
                    ]),
                    $columns
                );
            }
            try {
                $response = $next($request->withAttribute('accessLogID', $last), $response);
            } catch (\Exception $e) {
                if ($this->settings['writeOnce']) {
                    $this->writeAfter(
                        (new DateTime)->setTimestamp($_SERVER['REQUEST_TIME'])->format('Y-m-d H:i:s'),
                        parse_url($_SERVER['REQUEST_URI'])['path'],
                        $this::httpMethodID[$_SERVER['REQUEST_METHOD']],
                        json_encode([
                            'querystring' => $qs,
                            'body' => $request->getParsedBody()
                        ]),
                        (new DateTime())->format('Y-m-d H:i:s'),
                        $e->getCode(),
                        $e->getMessage(),
                        $columns
                    );
                } else {
                    $this->updateAfter(
                        $last,
                        (new DateTime())->format('Y-m-d H:i:s'),
                        $e->getCode(),
                        $e->getMessage(),
                        $columns
                    );
                }
                throw $e;
            }
            if ($this->settings['writeOnce']) {
                $this->writeAfter(
                    (new DateTime)->setTimestamp($_SERVER['REQUEST_TIME'])->format('Y-m-d H:i:s'),
                    parse_url($_SERVER['REQUEST_URI'])['path'],
                    $this::httpMethodID[$_SERVER['REQUEST_METHOD']],
                    json_encode([
                        'querystring' => $qs,
                        'body' => $request->getParsedBody()
                    ]),
                    (new DateTime())->format('Y-m-d H:i:s'),
                    $response->getStatusCode(),
                    $this->settings['captureResponse'] ? $response->getBody() : '',
                    $columns
                );
            } else {
                $this->updateAfter(
                    $last,
                    (new DateTime())->format('Y-m-d H:i:s'),
                    $response->getStatusCode(),
                    $this->settings['captureResponse'] ? $response->getBody() : '',
                    $columns
                );
            }
        }
        return $response;
    }

    /**
     * Determine if the current endpoint is under an ignored path, errors
     *  on the side of 'false' (allow) if there is an error in matching
     *
     * @param string $path
     * @return bool
     */
    protected function isIgnoredPath($path) {
        $uri = "/" . $path;
        $uri = preg_replace("#/+#", "/", $uri);
        foreach ((array)$this->settings["ignoredPaths"] as $ignore) {
            $ignore = rtrim($ignore, "/");
            return !!preg_match("@^{$ignore}(/.*)?$@", $uri);
        }
        return false;
    }

    //Credit: "Evan K"; https://www.php.net/manual/en/function.parse-str.php#76792
    private function proper_parse_str($str) {
        if (empty($str)) {
            return '';
        }
        $arr = [];
        $pairs = explode('&', $str);
        foreach ($pairs as $i) {
            list($name,$value) = explode('=', $i, 2);
            if( isset($arr[$name]) ) {
                if( is_array($arr[$name]) ) {
                    $arr[$name][] = $value;
                } else {
                    $arr[$name] = [$arr[$name], $value];
                }
            } else {
                $arr[$name] = $value;
            }
        }
        return $arr;
    }

    /**
     * @param string $reqTime   Format: Y-m-d H:i:s
     * @param string $reqUri    The endpoint requested
     * @param string $reqMethod One of self::httpMethodID
     * @param string $params    A JSON encoded array of ['querystring'=>[],'body'=>[]]
     * @param array  $columns   Custom column array ['columnName'=>'columnVal', ...]
     * @return bool|string False on error, else new record ID
     */
    private function writeBefore($reqTime, $reqUri, $reqMethod, $params, $columns = []) {
        $customCols = '';
        foreach ($columns as $k => $v) {
            $customCols .= ', ' . $k;
        }

        $query = $this->db->prepare('
            INSERT INTO ' . $this->settings['tableName'] . '
                (requestTime, requestUri, requestMethod, requestParams' . $customCols . ')
            VALUES
                (?, ?, ?, ?' . implode(', ', array_fill(0, count($columns), '?')) . ')
            ');
        $suc = $query->execute(
            array_merge(
                [
                    $reqTime,
                    $reqUri,
                    $reqMethod,
                    $params
                ],
                array_values($columns)
            )
        );
        if ($suc) {
            return $this->db->lastInsertId();
        } else {
            return false;
        }
    }

    /**
     * @param string $reqTime   Format: Y-m-d H:i:s
     * @param string $reqUri    The endpoint requested
     * @param string $reqMethod One of self::httpMethodID
     * @param string $params    A JSON encoded array of ['querystring'=>[],'body'=>[]]
     * @param string $resTime   The time of the response (Y-m-d H:i:s)
     * @param string $resCode   The HTTP status code
     * @param string $response  The response body
     * @param array  $columns   Custom column array ['columnName'=>'columnVal', ...]
     */
    private function writeAfter($reqTime, $reqUri, $reqMethod, $params, $resTime, $resCode, $response, $columns = []) {
        $customCols = '';
        foreach ($columns as $k => $v) {
            $customCols .= ', ' . $k;
        }

        $query = $this->db->prepare('
                INSERT INTO ' . $this->settings['tableName'] . '
                    (requestTime, requestUri, requestMethod, requestParams, responseTime, responseStatus, response' . $customCols . ')
                VALUES
                    (?, ?, ?, ?, ?, ?, ?' . implode(', ', array_fill(0, count($columns), '?')) . ')
            ');
        $query->execute(
            array_merge(
                [
                    $reqTime,
                    $reqUri,
                    $reqMethod,
                    $params,
                    $resTime,
                    $resCode,
                    $response
                ],
                array_values($columns)
            )
        );
    }

    /**
     * @param int    $insertId The ID of the record inserted by writeBefore()
     * @param string $resTime  The time of the response (Y-m-d H:i:s)
     * @param string $resCode  The HTTP status code
     * @param string $response The response body
     * @param array  $columns  Custom column array ['columnName'=>'columnVal', ...]
     */
    private function updateAfter($insertId, $resTime, $resCode, $response, $columns = []) {
        $customCols = '';
        foreach ($columns as $k => $v) {
            $customCols .= "\n                    $k = ?";
        }
        if (!empty($customCols)) {
            $customCols = ',' . $customCols;
        }

        $query = $this->db->prepare('
                UPDATE ' . $this->settings['tableName'] . '
                SET
                    responseTime = ?,
                    responseStatus = ?,
                    response = ?' . $customCols . '
                WHERE ' . $this->settings['idColumn'] . ' = ?
            ');
        $query->execute(
            array_merge(
                [
                    $resTime,
                    $resCode,
                    $response
                ],
                array_values($columns),
                [
                    $insertId
                ]
            )
        );
    }

}
