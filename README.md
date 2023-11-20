<p align="center"><img src="https://m-finder.github.io/images/avatar.jpeg"></p>
<p align="center">
<img src="https://img.shields.io/badge/Author-m--finder-red">
<img src="https://img.shields.io/badge/Laravel-9.52.0-red">
<img src="https://img.shields.io/badge/Swoole-5.0.3-red">
<a href="https://packagist.org/packages/wu/giorgio-socket"><img src="https://img.shields.io/badge/License-MIT-green" alt="License"></a>
</p>

## About Giorgio Socket
Add a socket server based on Swoole to your Laravel application.

### Preview
![](https://repository-images.githubusercontent.com/721082370/0240b5fa-69e2-4bf0-89f2-fbed407c2b54)


### Install

Require socket server
```
composer require wu/giorgio-socket
```

Publish config
```
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider"
```

Start socket server
```
php artisan socket:start
```

### Important Considerations

* Redis is required.
* You can customize your own business logic by implementing the interfaces under the folder `GiorgioSocket\Services\Handlers\Interfaces`.
* If you want to send messages from the server, you need to modify the `QUEUE_CONNECTION` configuration in the .env file to "redis" or another asynchronous queue driver. After making the configuration change, you should run the following command: `php artisan queue:work`, You can invoke it as shown in the following code.
    ```
    Route::any('socket', function (Request $request){
        \GiorgioSocket\Events\SocketEvent::dispatch($request->get('to'), $request->get('message'));
    });
    ```
* If you are using the `laravel/breeze` package and working with Blade templates, you can paste the following code into `dashboard.blade.php` for a quick test.
  ```
  @auth
      <div class="py-12">
          <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
              <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                  <div class="grid grid-cols-1 md:grid-cols-2">
                      <div class="p-6" id="server-message">
                          messages：<br/>
                      </div>

                      <div class="p-6">
                          <label class="block font-medium text-sm text-gray-700 dark:text-gray-300" for="from">from</label>
                          <input class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm block mt-1 w-full" value="{{ auth()->user()->getKey() }}" id="from">
                          <label class="block font-medium text-sm text-gray-700 dark:text-gray-300" for="to">to</label>
                          <input class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm block mt-1 w-full" value="" id="to">
                          <label class="block font-medium text-sm text-gray-700 dark:text-gray-300" for="message">message</label>
                          <textarea class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm block mt-1 w-full" id="message"></textarea>
                          <input class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150 mt-3" type="button" id="submit" value="submit">
                      </div>
                  </div>
              </div>
          </div>
      </div>
      <script type="text/javascript">
        let heartBeatTimer = 0;
        let socket = connectWebSocket();

        function startHeartbeat(interval) {
          interval = interval || 30;
          heartBeatTimer = setInterval(function () {
            sendMessage(null, "heart_beat");
          }, interval * 1000);
        }

        function stopHeartbeat() {
          clearInterval(heartBeatTimer);
        }

        function connectWebSocket() {
          const wsServer = 'ws://127.0.0.1:9501';
          const socket = new WebSocket(wsServer);

          let userId = document.getElementById('from').value;
          socket.onopen = function (evt) {
            let data = {
              user_id: userId,
              type: 'connect'
            };
            console.log('open', data)
            socket.send(JSON.stringify(data));
          };


          socket.onmessage = function (evt) {
            console.log('get message from server: ' + evt.data);

            if (evt.data !== 'heart_beat') {
              let data = JSON.parse(evt.data);
              let message = document.getElementById("server-message")
              message.innerHTML += data.user_name + ': ' + data.data + '<br/>'
            }
          };

          socket.onerror = function (evt) {
            console.log(evt);
          };

          socket.onclose = function () {
            let data = {
              user_id: userId,
              type: 'close'
            };
            socket.send(JSON.stringify(data));
          };
          return socket;
        }

        function sendMessage(to, message) {
          if (socket != null && socket.readyState === WebSocket.OPEN) {
            if (message !== 'heart_beat') {
              let messageBox = document.getElementById("server-message")
              messageBox.innerHTML += 'me: ' + message + '<br/>'
            }
            let from = document.getElementById("from")
            socket.send(JSON.stringify({
              user_id: from.value,
              user_name: '{{ auth()->user()->name }}',
              to: to,
              type: 'message',
              data: message,
            }));
            console.log("webSocket send message：" + JSON.stringify({
              user_id: from.value,
              user_name: '{{ auth()->user()->name }}',
              to: to,
              type: 'message',
              data: message,
            }));
          } else {
            console.log("webSocket closed");
          }
        }

        let button = document.getElementById("submit");
        button.addEventListener('click', function () {
          let message = document.getElementById("message");
          let to = document.getElementById("to");
          sendMessage(to.value, message.value)
        });

      </script>
  @endauth
  ```
### License

The Giorgio Socket is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
