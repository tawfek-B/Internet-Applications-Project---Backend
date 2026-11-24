//

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

import Pusher from 'pusher-js';

const pusher = new Pusher(
    import.meta.env.VITE_REVERB_APP_KEY,
    {
        cluster: 'mt1',
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT),
        forceTLS: false,
        enabledTransports: ['ws'],
    }
);

pusher.connection.bind('connected', () => {
    console.log('Connected to Reverb!');
});

pusher.connection.bind('error', (error) => {
    console.error('Reverb connection error:', error);
});

console.log(pusher, "LOL");

const channel = pusher.subscribe('test');


channel.bind('pusher:subscription_succeeded', () => {
    console.log('Successfully subscribed to test channel!');
});

channel.bind('test.message', (data) => {
    console.log('RECEIVED EVENT:', data);
});

const adminChannel = pusher.subscribe('private-admin.orders');

adminChannel.bind('order.created', (data) => {
    console.log('NEW ORDER:', data);
});

adminChannel.bind('order.status.changed', (data) => {
    console.log('ORDER STATUS CHANGED:', data);
});