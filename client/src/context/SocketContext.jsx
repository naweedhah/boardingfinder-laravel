import { createContext, useContext, useEffect, useState } from "react";
import { AuthContext } from "./AuthContext";
import { createEcho } from "../lib/echo";

export const SocketContext = createContext();

/**
 * Provides a Laravel Echo connection (`echo`) while a user is signed in. Components subscribe
 * to that user's private channel, `users.{id}`, for notifications and incoming chat messages.
 */
export const SocketContextProvider = ({ children }) => {
  const { currentUser } = useContext(AuthContext);
  const [echo, setEcho] = useState(null);

  useEffect(() => {
    if (!currentUser?.id) {
      setEcho(null);
      return undefined;
    }

    const nextEcho = createEcho();
    setEcho(nextEcho);

    return () => {
      nextEcho.disconnect();
    };
  }, [currentUser?.id]);

  return (
    <SocketContext.Provider value={{ echo }}>
      {children}
    </SocketContext.Provider>
  );
};
