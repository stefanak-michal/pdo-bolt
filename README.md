# PDO bolt

The PHP Data Objects (PDO) extension defines a lightweight, consistent interface for accessing databases in PHP. This
library is dedicated to add bolt protocol. This protocol was created by [Neo4j](https://neo4j.com/) and is used by graph
databases like Neo4j, Memgraph or Amazon Neptune.

Right now there is no official C/C++ bolt driver and therefore it is not possible to make this library as extension.
Even the unofficial driver looks like it is not maintained. Maybe in future it will change but before that you are
welcomed to use this one.

[Bolt specification](https://neo4j.com/docs/bolt/current/)

<a href='https://ko-fi.com/Z8Z5ABMLW' target='_blank'><img height='36' style='border:0px;height:36px;' src='https://cdn.ko-fi.com/cdn/kofi1.png?v=3' border='0' alt='Buy Me a Coffee at ko-fi.com' /></a>

## Requirements

- PHP ^8
- [Bolt library](https://packagist.org/packages/stefanak-michal/bolt)

## Installation

You can use composer or download this repository from GitHub and manually implement it.

### Composer

Run the following command in your project to install the latest applicable version of the package:

`composer require stefanak-michal/pdo-bolt`

[Packagist](https://packagist.org/packages/stefanak-michal/pdo-bolt)

## Usage

You can use this library as typical PDO, but you have to create instance of `\pdo_bolt\PDO` which overrides `\PDO` and
adds bolt scheme.

```php
$pdo = new \pdo_bolt\PDO('bolt:host=localhost;port=7687;appname=pdo-bolt', 'neo4j', 'neo4j');
$stmt = $pdo->prepare('RETURN $n AS num, $s AS str');
$stmt->bindValue('n', 123, \PDO::PARAM_INT);
$stmt->bindValue('s', 'hello');
$stmt->execute();
$stmt->setFetchMode(\PDO::FETCH_ASSOC);
foreach ($stmt AS $row) {
    print_r($row);
}

/* output:
Array
(
    [num] => 123
    [str] => hello
)
*/
```

For more information about how to use PDO follow official documentation
at [php.net](https://www.php.net/manual/en/book.pdo.php).

### DSN

| Key     | Description                                             |
|---------|---------------------------------------------------------|
| host    | The hostname on which the database server resides.      |
| port    | The port number where the database server is listening. |
| dbname  | The name of the database.                               |
| appname | The application name (used as UserAgent).               |

### PDO constructor available options

| Key               | Description                                                                                                               |
|-------------------|---------------------------------------------------------------------------------------------------------------------------|
| auth              | Specify authentication method (none/basic/bearer/kerberos). Automatically detected.                                       |
| ssl               | Enable encrypted communication and set ssl context options (see [php.net](https://www.php.net/manual/en/context.ssl.php)) |
| protocol_versions | Specify requested bolt versions. Array of versions as int/float/string.                                                   |

_Check method annotation for more informations._

## Bolt specific features

### Parameter placeholders

Supported parameter placeholder for CQL is `?` or string with `$` prefix.

_Don't use placeholder string with `:` prefix because CQL use `:` for labels prefix._

### PDO Method `reset()`

Graph database can be in failure state (_error code 02001_) and any next message is ignored (_error code 02002_).
Instead of destroying PDO instance and creating new, you can call this method.

### PDO::PARAM_LOB

Automatically converts resource or string into instance of Bytes class.

## Additional bolt parameter types

| Constant                   | Description                                                                                                                       |
|----------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| PDO::BOLT_PARAM_FLOAT      |                                                                                                                                   |
| PDO::BOLT_PARAM_LIST       | Array witch consecutive numeric keys from 0.                                                                                      | 
| PDO::BOLT_PARAM_DICTIONARY | Object or array which is not list.                                                                                                |
| PDO::BOLT_PARAM_STRUCTURE  | Class extending IStructure ([available structures](https://neo4j.com/docs/cypher-manual/current/syntax/values/#structural-types)) |
| PDO::BOLT_PARAM_BYTES      | instance of Bytes class                                                                                                           |

## Not supported PDO features with Bolt

- Fetch mode `PDO::FETCH_LAZY`
- Fetch mode `PDO::FETCH_NAMED`
- PDO method `lastInsertId()`
- PDOStatement method `rowCount()`
- Scrollable cursor

## Bolt error codes

Standard PDO error codes are related to SQL databases. Graph database have CQL (Cypher query language) and therefore I
had to create new list of error codes.

| Error code | Description                                 |
|------------|---------------------------------------------|
| 01000      | Any authentification error                  |
| 01001      | Wrong credentials                           |
| 01002      | Undefined auth type                         |
| 02000      | Any bolt message error                      |
| 02001      | Bolt message failure                        |
| 02002      | Bolt message ignored                        |
| 03000      | Any transaction error                       |
| 03001      | Transaction not supported (required bolt>=3 |
| 04000      | Any attribute error                         |
| 04001      | Attribute not supported                     |
| 05000      | Any parameter error                         |
| 05001      | Parameter type not supported                |
| 05002      | Parameters placeholder mismatch             |
| 06000      | Any fetch error                             |
| 06001      | Requested fetch column not defined          |
| 06002      | Fetch object error                          |
| 07000      | Any underlying bolt library error           |
