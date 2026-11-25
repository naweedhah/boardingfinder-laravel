import HomePage from "./routes/homePage/homePage";
import { createBrowserRouter, RouterProvider } from "react-router-dom";
import ListPage from "./routes/listPage/listPage";
import { Layout, RequireAuth } from "./routes/layout/layout";
import SinglePage from "./routes/singlePage/singlePage";
import ProfilePage from "./routes/profilePage/profilePage";
import Login from "./features/auth/pages/login";
import Register from "./features/auth/pages/register";
import ProfileUpdatePage from "./routes/profileUpdatePage/profileUpdatePage";
import NewPostPage from "./routes/newPostPage/newPostPage";
import {
  listPageLoader,
  profilePageLoader,
  roommatePageLoader,
  singlePageLoader,
  watchlistPageLoader,
} from "./lib/loaders";
import WatchlistPage from "./features/watchlist/pages/watchlistPage";
import RoommatePage from "./features/roommate/pages/roommatePage";
import InquiryBox from "./features/safety/components/InquiryBox";
import AdminDashboard from "./features/safety/pages/AdminDashboard";
import ChatPage from "./features/safety/pages/ChatPage";

import BoardingFinder from "./features/ownerDashboard/BoardingFinder";
import BookingManagementPage from "./features/booking/pages/BookingManagementPage";

function App() {
  const router = createBrowserRouter([
    {
      path: "/",
      element: <Layout />,
      children: [
        {
          path: "/",
          element: <HomePage />,
        },
        {
          path: "/list",
          element: <ListPage />,
          loader: listPageLoader,
        },
        {
          path: "/:id",
          element: <SinglePage />,
          loader: singlePageLoader,
        },
        {
          path: "/login",
          element: <Login />,
        },
        {
          path: "/register",
          element: <Register />,
        },
      ],
    },
    {
      path: "/",
      element: <RequireAuth />,
      children: [
        {
          path: "/profile",
          element: <ProfilePage />,
          loader: profilePageLoader
        },
        {
          path: "/watchlist",
          element: <WatchlistPage />,
          loader: watchlistPageLoader,
        },
        {
          path: "/roommates",
          element: <RoommatePage />,
          loader: roommatePageLoader,
        },
        {
          path: "/profile/update",
          element: <ProfileUpdatePage />,
        },
        {
          path: "/add",
          element: <NewPostPage />,
        },
        {
          path: "/inquiry",
          element: <InquiryBox />
        },
        {
          path: "/admin",
          element: <AdminDashboard />
        },
        {
          path: "/chat/:chatId",
          element: <ChatPage />
        },
        {
          path: "/boardings",
          element: <BoardingFinder />
        },
        {
          path: "/bookings",
          element: <BookingManagementPage />
        }
      ],
    },
  ]);

  return <RouterProvider router={router} />;
}

export default App;