import Echo from "laravel-echo";
import Pusher from "pusher-js";
import apiRequest from "./apiRequest";

// Laravel Reverb speaks the Pusher protocol; Echo needs the Pusher client on `window`.
window.Pusher = Pusher;

const env = import.meta.env;

/**
 * Real-time connection to Laravel Reverb. Private channels are authorised through the API
 * (`POST /api/broadcasting/auth`) using the same httpOnly login cookie as every other request.
 */
export const createEcho = () =>
  new Echo({
    broadcaster: "reverb",
    key: env.VITE_REVERB_APP_KEY || "boardingfinder-local-key",
    wsHost: env.VITE_REVERB_HOST || "localhost",
    wsPort: env.VITE_REVERB_PORT || 8080,
    wssPort: env.VITE_REVERB_PORT || 8080,
    forceTLS: (env.VITE_REVERB_SCHEME || "http") === "https",
    enabledTransports: ["ws", "wss"],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        apiRequest
          .post("/broadcasting/auth", {
            socket_id: socketId,
            channel_name: channel.name,
          })
          .then((response) => callback(null, response.data))
          .catch((error) => callback(error));
      },
    }),
  });
