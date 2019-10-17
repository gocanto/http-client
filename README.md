## About it

<a href="https://packagist.org/packages/gocanto/http-client"><img src="https://img.shields.io/packagist/dt/gocanto/http-client.svg?style=flat-square" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/gocanto/http-client"><img src="https://img.shields.io/github/v/release/gocanto/http-client.svg?style=flat-square" alt="Latest Stable Version"></a>
<a href="https://travis-ci.org/gocanto/http-client"><img src="https://img.shields.io/travis/gocanto/http-client/master.svg?style=flat-square" alt="Build status"></a>

This library is a wrapper around the famous Guzzle `HTTP` client with some goodies on top of it. 

This client gives you the ability to perform `retries` and `log` any request information you my need. 

## Installation

This library uses [Composer](https://getcomposer.org) to manage its dependencies. So, before using it, make sure you have it installed in your machine. 
Once you have done this, you will be able to pull this library in by typing the following command in your terminal.

```
composer require gocanto/http-client
```

## The reason behind

Sometimes you will need the ability to retry a HTTP request for some given reason. This can be either due to latency issues or some timeout errors. 

To avoid this interruptions, we usually perform a retry action inside a given loop. Then, we either break the loop because we have a valid response to return with or because we need to handle possible errors. 

Therefore, I have created this small wrapper to handle retries actions and to log our requests/responses payload within the same action.

## How does it work? 

The way how this client works is exactly the same as the one you have been used to it. You will be able to call any known guzzle method and attach the `retry` action to them.

Let's say you need to perform some HTTP request to fill seed your db with some data. To do so, you would have to write some code along these lines.

```php
use GuzzleHttp\Client;

$response = (new Client)->get('http://foo.com'); 
```

This is a simple use case that we all have came across some other time. But, What would you do if there is an error and you need to handle some retires?

Well, if you are anything like me, you would do something like:

```php
use GuzzleHttp\Client;

$retry = 1;
$response = null;

do {
    $response = (new Client)->get('http://foo.com');
} while ($response === null && $retry <= 5);
```

There you have a working code, but you will have to do the same procedure every time you need to perform some kind of HTTP request. 

> We can do better!

By using the HTTP client shipped within this package, you will be able to call the retry mechanism within the same HTTP call. For Example:"

```php
use GuzzleHttp\Client;

$response = (new Client)->retry(5)->get('http://foo.com'); 
```

This line of code does exactly the same as the ones above, but more efficient and elegant. This library also ships a different method `onRetry()` that performs the same retries, but it also gives the ability to hook into the retry call. 

You would be able to use it like so: 

```php
use GuzzleHttp\Client;

$response = (new Client)->onRetry(function () {})->get('http://foo.com'); 
```

Here, you will be given the incoming request and response that you are handling in that particular moment. [see more](https://github.com/gocanto/http-client/blob/master/src/HttpClient.php#L82)


## Contributing

Please feel free to fork this package and contribute by submitting a pull request to enhance its functionality.

## License

The MIT License (MIT). Please see [License File](https://github.com/gocanto/http-client/blob/master/LICENSE.md) for more information.


## How can I thank you?
Why not star the github repo and share the link for this repository on Twitter?


Don't forget to [follow me on twitter](https://twitter.com/gocanto)!

Thanks!

Gustavo Ocanto.
