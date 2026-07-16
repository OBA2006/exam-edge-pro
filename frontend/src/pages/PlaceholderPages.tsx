import React from 'react';

export function PlaceholderPage({ title }: { title: string }) {
  return (
    <div style={{ padding: '60px 24px', textAlign: 'center' }}>
      <div style={{ fontSize: 40, marginBottom: 12, opacity: 0.4 }}>🚧</div>
      <h2 style={{ fontSize: 18, fontWeight: 600, marginBottom: 6 }}>{title}</h2>
      <p style={{ fontSize: 13, color: '#6b7280' }}>This page is under construction. Backend API endpoints are fully implemented and ready to connect.</p>
    </div>
  );
}
