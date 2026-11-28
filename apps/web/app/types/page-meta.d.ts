declare module '#app' {
  interface PageMeta {
    /** 'required' (default): signed in. 'guest': only signed out (sign-in, sign-up). 'public': anyone. */
    auth?: 'required' | 'guest' | 'public'
  }
}

export {}
