import { useEffect, useState } from 'react';
import { api } from './api';
import AuthPage from './AuthPage';
import Lobby from './Lobby';
import Game from './Game';

export default function App() {
  const [user, setUser] = useState(null);
  const [view, setView] = useState('boot'); // boot | auth | lobby | game
  const [roomId, setRoomId] = useState(null);

  useEffect(() => {
    if (!api.getToken()) {
      setView('auth');
      return;
    }
    api.me()
      .then((u) => { setUser(u); setView('lobby'); })
      .catch(() => { api.logout(); setView('auth'); });
  }, []);

  const onAuthed = (u) => { setUser(u); setView('lobby'); };

  const logout = () => {
    api.logout();
    setUser(null);
    setView('auth');
  };

  if (view === 'boot' || view === 'auth') {
    return view === 'boot'
      ? <div className="auth-wrap"><div className="spin" /></div>
      : <AuthPage onAuthed={onAuthed} />;
  }

  if (view === 'game') {
    return (
      <Game
        user={user}
        roomId={roomId}
        onBack={() => setView('lobby')}
        onUser={(u) => setUser(u)}
      >
      </Game>
    );
  }

  return <Lobby user={user} onPlay={(rid) => { setRoomId(rid); setView('game'); }} onLogout={logout} />;
}